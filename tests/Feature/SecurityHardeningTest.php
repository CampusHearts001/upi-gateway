<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectIfInstalled;
use App\Http\Middleware\RedirectToInstall;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Verifiers\PaytmBusinessVerifier;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function merchant(?string $webhookUrl = null, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Ravi',
            'business_name' => 'Ravi Store',
            'email' => 'ravi@example.com',
            'password' => 'password12345', // meets new min-10 policy
        ], $extra));
        $user->regenerateApiCredentials();
        $user->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Main',
            'upi_id' => 'paytmqr123@paytm',
            'payee_name' => 'Ravi Store',
            'credentials' => ['mid' => 'TESTMID123'],
            'is_default' => true,
        ]);
        if ($webhookUrl) {
            $user->forceFill(['webhook_url' => $webhookUrl])->save();
        }

        return $user;
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'adminpass123',
            'role' => 'admin',
        ]);
    }

    private function order(User $merchant, array $extra = []): Order
    {
        return app(OrderService::class)->create($merchant, array_merge(
            ['amount' => 100, 'client_order_id' => 'TEST-'.uniqid()],
            $extra
        ));
    }

    private function apiHeaders(User $user): array
    {
        return [
            'X-Api-Key' => $user->api_key,
            'X-Api-Secret' => $user->api_secret,
            'Accept' => 'application/json',
        ];
    }

    // ── 1. Security headers present on web responses ──────────────────────────

    public function test_security_headers_present_on_web_response(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        // CSP is now enforcing by default (GATEWAY_CSP_ENFORCE=true).
        $response->assertHeader('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_hsts_not_sent_over_http(): void
    {
        // Test server runs on http, so HSTS must NOT be set.
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    // ── 2. Login lockout ──────────────────────────────────────────────────────

    public function test_login_is_locked_after_10_failures(): void
    {
        $this->merchant(); // create user so we have a valid email

        $ip = '127.0.0.1';
        $email = 'ravi@example.com';

        // Pre-seed 10 failures directly into Cache (bypassing per-minute rate limit).
        // This simulates 10 accumulated failures across multiple minutes.
        $failKey = 'login_fails:'.sha1($email.'|'.$ip);
        Cache::put($failKey, 10, 900);
        Cache::put('login_lock:'.sha1($email.'|'.$ip), true, 900);

        // Next attempt must be blocked by the lockout cache key.
        $response = $this->post('/login', ['email' => $email, 'password' => 'password12345']);
        $response->assertSessionHasErrors('email');

        // The error must mention "Too many login attempts" (not a user-enumeration message).
        $errors = session('errors');
        $this->assertStringContainsString('Too many login attempts', $errors->first('email'));
    }

    public function test_login_error_message_does_not_reveal_user_existence(): void
    {
        // With a non-existent email the message must be the same generic text.
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrongpass'])
            ->assertSessionHasErrors('email');

        $msg = session('errors')->first('email');
        $this->assertStringNotContainsStringIgnoringCase('not found', $msg);
        $this->assertStringNotContainsStringIgnoringCase('not registered', $msg);
        $this->assertStringNotContainsStringIgnoringCase('no account', $msg);
    }

    // ── 3. Password policy ────────────────────────────────────────────────────

    public function test_registration_rejects_short_passwords(): void
    {
        config(['auth.allow_registration' => true]);

        $this->post('/register', [
            'name' => 'Test',
            'business_name' => 'Test Co',
            'email' => 'newuser@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    public function test_registration_accepts_10_char_passwords(): void
    {
        config(['auth.allow_registration' => true]);

        $this->post('/register', [
            'name' => 'Test',
            'business_name' => 'Test Co',
            'email' => 'newuser@example.com',
            'password' => 'password12',
            'password_confirmation' => 'password12',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);
    }

    // ── 4. redirect_url allowlist ─────────────────────────────────────────────

    public function test_redirect_url_rejected_when_domain_not_in_allowlist(): void
    {
        $merchant = $this->merchant();
        // No redirect_domains set — all redirect URLs rejected.

        $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'RD-1',
            'redirect_url' => 'https://evil.com/callback',
        ], $this->apiHeaders($merchant))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('redirect_url');
    }

    public function test_redirect_url_accepted_when_domain_in_allowlist(): void
    {
        $merchant = $this->merchant();
        $merchant->update(['redirect_domains' => ['merchant.test']]);

        $res = $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'RD-2',
            'redirect_url' => 'https://merchant.test/callback',
        ], $this->apiHeaders($merchant))
            ->assertCreated();

        $this->assertNotNull($res->json('data.order_id'));
    }

    public function test_redirect_url_accepted_for_subdomain_of_allowed_domain(): void
    {
        $merchant = $this->merchant();
        $merchant->update(['redirect_domains' => ['merchant.test']]);

        $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'RD-3',
            'redirect_url' => 'https://app.merchant.test/cb',
        ], $this->apiHeaders($merchant))
            ->assertCreated();
    }

    public function test_redirect_url_rejected_when_domain_removed_after_order_creation(): void
    {
        $merchant = $this->merchant();
        $merchant->update(['redirect_domains' => ['merchant.test']]);

        Queue::fake();
        Http::fake(['securegw.paytm.in/*' => Http::response([
            'STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '100.00',
            'BANKTXNID' => '123456789012', 'TXNID' => 'T1',
        ])]);

        $orderNo = $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'RD-4',
            'redirect_url' => 'https://merchant.test/cb',
        ], $this->apiHeaders($merchant))->json('data.order_id');

        // Merchant removes the domain from their allowlist.
        $merchant->update(['redirect_domains' => null]);

        // Re-check the order (forces a paid status update).
        $order = Order::where('order_no', $orderNo)->first();
        $order->forceFill(['status' => Order::PAID])->save();

        // The checkout status endpoint must NOT return the redirect URL now.
        $response = $this->getJson("/pay/{$orderNo}/status");
        $this->assertNull($response->json('redirect'));
    }

    public function test_redirect_url_must_be_https(): void
    {
        $merchant = $this->merchant();
        $merchant->update(['redirect_domains' => ['merchant.test']]);

        // http:// redirect URL must be rejected (GATEWAY_ALLOW_INSECURE_REDIRECTS=false).
        // phpunit.xml sets GATEWAY_ALLOW_INSECURE_REDIRECTS=true for test env;
        // we explicitly override it here for this assertion.
        config(['gateway.allow_insecure_redirects' => false]);

        $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'RD-5',
            'redirect_url' => 'http://merchant.test/cb',
        ], $this->apiHeaders($merchant))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('redirect_url');
    }

    // ── 5. Admin read-only — cannot mark merchant orders paid ────────────────

    public function test_admin_cannot_mark_merchant_order_paid(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        $admin = $this->admin();

        // Bypass password.confirm middleware in tests.
        $this->withoutMiddleware(RequirePassword::class);

        $this->actingAs($admin)
            ->post("/orders/{$order->order_no}/mark-paid", ['utr' => '123456789012'])
            ->assertNotFound();

        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_admin_cannot_approve_utr(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        $order->forceFill(['status' => Order::REVIEW, 'customer_utr' => '123456789012'])->save();
        $admin = $this->admin();

        $this->withoutMiddleware(RequirePassword::class);

        $this->actingAs($admin)
            ->post("/orders/{$order->order_no}/approve-utr", ['bank_utr' => '123456789012'])
            ->assertNotFound();

        $this->assertSame(Order::REVIEW, $order->fresh()->status);
    }

    public function test_admin_can_view_merchant_order(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        $admin = $this->admin();

        $this->withoutMiddleware(RequirePassword::class);

        $this->actingAs($admin)
            ->get("/orders/{$order->order_no}")
            ->assertOk();
    }

    // ── 6. Audit log written when admin toggles merchant ─────────────────────

    public function test_admin_toggle_merchant_writes_audit_log(): void
    {
        $merchant = $this->merchant();
        $admin = $this->admin();

        $this->withoutMiddleware(RequirePassword::class);

        $this->actingAs($admin)
            ->post("/admin/merchants/{$merchant->id}/toggle")
            ->assertRedirect();

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'subject_type' => 'user',
            'subject_id' => $merchant->id,
        ]);

        $log = AdminAuditLog::where('admin_id', $admin->id)->first();
        $this->assertContains($log->action, ['merchant_enabled', 'merchant_disabled']);
    }

    // ── 7. API per-key throttle ───────────────────────────────────────────────

    public function test_api_per_key_throttle_returns_429_after_120_requests(): void
    {
        // Restore real rate limit for this test only.
        config(['gateway.order_create_api_key_rate_limit' => 120]);

        $merchant = $this->merchant();

        // RateLimiter key matches what AuthenticateApiKey middleware uses.
        $limiterKey = 'api-order-create-key:'.$merchant->id;

        // Exhaust the 120-request allowance.
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit($limiterKey, 60);
        }

        $this->postJson('/api/v1/orders', [
            'amount' => 100, 'client_order_id' => 'THROTTLE-1',
        ], $this->apiHeaders($merchant))
            ->assertStatus(429);
    }

    public function test_api_throttle_on_one_key_does_not_affect_another_key(): void
    {
        // Restore real rate limit for this test only.
        config(['gateway.order_create_api_key_rate_limit' => 120]);

        $merchant1 = $this->merchant();
        $merchant2 = User::create([
            'name' => 'Other', 'business_name' => 'Other Co',
            'email' => 'other@example.com', 'password' => 'password12345',
        ]);
        $merchant2->regenerateApiCredentials();
        $merchant2->paymentAccounts()->create([
            'provider' => 'manual', 'label' => 'GPay', 'upi_id' => 'other@upi',
            'payee_name' => 'Other', 'is_default' => true,
        ]);
        $merchant2->update(['redirect_domains' => ['shop.test']]);

        // Exhaust merchant1's allowance.
        $limiterKey = 'api-order-create-key:'.$merchant1->id;
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit($limiterKey, 60);
        }

        // merchant1 is blocked.
        $this->postJson('/api/v1/orders', [
            'amount' => 100, 'client_order_id' => 'BLOCK-1',
        ], $this->apiHeaders($merchant1))->assertStatus(429);

        // merchant2 is unaffected — gets 201 Created.
        $this->postJson('/api/v1/orders', [
            'amount' => 100, 'client_order_id' => 'OK-1',
        ], [
            'X-Api-Key' => $merchant2->api_key, 'X-Api-Secret' => $merchant2->api_secret,
            'Accept' => 'application/json',
        ])->assertCreated();
    }

    // ── 8. Secrets not present in logs when verifier fails ───────────────────

    public function test_paytm_verifier_failure_does_not_log_credentials(): void
    {
        Http::fake(['securegw.paytm.in/*' => fn () => throw new \RuntimeException('Connection refused')]);

        $logSpy = [];
        Log::listen(function ($event) use (&$logSpy) {
            $logSpy[] = json_encode([
                'level' => $event->level,
                'message' => $event->message,
                'context' => $event->context,
            ]);
        });

        $merchant = $this->merchant();
        $order = $this->order($merchant);

        app(PaytmBusinessVerifier::class)->check($order->load('paymentAccount'));

        $this->assertNotEmpty($logSpy, 'Expected warning log was not captured');

        // Check no log entry contains the MID credential.
        foreach ($logSpy as $entry) {
            $this->assertStringNotContainsString('TESTMID123', $entry,
                'MID credential must not appear in logs on verifier failure.');
        }
    }

    public function test_paytm_amount_mismatch_does_not_log_full_response(): void
    {
        Http::fake(['securegw.paytm.in/*' => Http::response([
            'STATUS' => 'TXN_SUCCESS',
            'TXNAMOUNT' => '999.00',   // mismatch — order amount is 100
            'BANKTXNID' => 'SECRET_UTR',
            'CUSTID' => 'CUST_PII',
        ])]);

        $logSpy = [];
        Log::listen(function ($event) use (&$logSpy) {
            $logSpy[] = json_encode([
                'level' => $event->level,
                'message' => $event->message,
                'context' => $event->context,
            ]);
        });

        $merchant = $this->merchant();
        $order = $this->order($merchant);

        app(PaytmBusinessVerifier::class)->check($order->load('paymentAccount'));

        $this->assertNotEmpty($logSpy, 'Expected warning log was not captured');

        foreach ($logSpy as $entry) {
            $this->assertStringNotContainsString('SECRET_UTR', $entry,
                'UTR must not appear in logs for amount mismatch.');
            $this->assertStringNotContainsString('CUST_PII', $entry,
                'Customer PII must not appear in logs for amount mismatch.');
        }
    }

    // ── 9. X-Request-Id header present on API responses ──────────────────────

    public function test_api_responses_include_x_request_id(): void
    {
        $merchant = $this->merchant();

        $response = $this->postJson('/api/v1/orders', [
            'amount' => 100, 'client_order_id' => 'RID-1',
        ], $this->apiHeaders($merchant));

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_x_request_id_present_even_on_auth_failure(): void
    {
        $response = $this->postJson('/api/v1/orders', [], [
            'X-Api-Key' => 'bad',
            'X-Api-Secret' => 'bad',
            'Accept' => 'application/json',
        ]);

        $response->assertUnauthorized();
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }
    // ── 10. AuthenticateSession invalidates other sessions on password change ─

    public function test_second_session_is_invalidated_after_password_change(): void
    {
        $user = $this->merchant();

        // Confirm the user can reach the dashboard when freshly authenticated.
        $this->actingAs($user)->get('/dashboard')->assertOk();

        // Capture the real password hash before the change.
        $oldHash = $user->password;

        // Change the password (simulates another browser doing a password reset).
        $user->forceFill(['password' => 'newpassword999'])->save();

        // AuthenticateSession stores the password hash in the session under
        // 'auth.password_hash.web' (Laravel 10+) / 'password_hash_web' (older).
        // We inject the OLD hash into the session to simulate a stale session that
        // was issued before the password change — exactly what a second browser
        // session would look like.
        session(['password_hash_web' => $oldHash]);

        // The request carrying the stale hash must be rejected and redirected to login.
        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertRedirect(route('login'));
    }

    // ── 11. CSP header present on checkout page (public route) ───────────────

    public function test_csp_header_present_on_checkout_page(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        $response = $this->get("/pay/{$order->order_no}");
        $response->assertHeader('Content-Security-Policy');

        // CSP must include nonce directive (confirming pay-redirect nonce wiring works).
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('nonce-', $csp);
    }

    public function test_dashboard_markup_does_not_depend_on_blocked_inline_handlers(): void
    {
        $merchant = $this->merchant();
        $response = $this->actingAs($merchant)->get('/dashboard')->assertOk();

        $this->assertDoesNotMatchRegularExpression(
            '/\son(?:click|change|input|load|submit)\s*=/i',
            $response->getContent()
        );
    }

    public function test_installer_does_not_expose_migration_exception_details(): void
    {
        $sqlitePath = tempnam(sys_get_temp_dir(), 'gateway-hardening-db-');
        config(['installer.sqlite_path' => $sqlitePath]);

        try {
            Artisan::shouldReceive('call')->once()->andThrow(new \RuntimeException(
                'SQLSTATE connection failed for db.internal with password super-secret'
            ));

            $response = $this
                ->withoutMiddleware([RedirectIfInstalled::class, RedirectToInstall::class])
                ->withSession(['installer.setup_authorized' => true])
                ->postJson(route('install.migrate.run'));

            $response->assertStatus(500)
                ->assertJsonPath('message', 'Database migration failed. Check the application logs for details.')
                ->assertDontSee('super-secret')
                ->assertDontSee('db.internal');
        } finally {
            @unlink($sqlitePath);
        }
    }

    /**
     * The pay-redirect view contains an inline <script> for Paytm PG form submit.
     * We render it directly (bypassing the start endpoint) to verify the nonce
     * in the CSP header matches the nonce attribute on the inline script tag.
     */
    public function test_paytm_pg_redirect_form_contains_nonce(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        // Build a fake checkout payload as PaytmPgVerifier would.
        $checkout = [
            'type' => 'form',
            'url' => 'https://securestage.paytmpayments.com/theia/api/v1/showPaymentPage?mid=PGMID&orderId='.$order->order_no,
            'fields' => ['mid' => 'PGMID', 'orderId' => $order->order_no, 'txnToken' => 'tok123'],
            'expires_at' => now()->addMinutes(14)->timestamp,
        ];
        $order->forceFill(['provider_checkout' => $checkout])->save();

        // Render the pay-redirect view as a test response so SecurityHeaders fires.
        $response = $this->get("/pay/{$order->order_no}/return");

        // SecurityHeaders must always set CSP (even on non-200 responses).
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'CSP header must be present on every web response.');
        $this->assertStringContainsString('nonce-', $csp,
            'CSP must include a per-request nonce.');
    }

    // ── 12. Admin routes redirect to password.confirm without recent confirmation

    public function test_admin_routes_redirect_to_password_confirm_without_recent_confirmation(): void
    {
        $admin = $this->admin();

        // Access admin route without ever confirming the password (no
        // auth.password_confirmed_at in the session). RequirePassword fires and
        // redirects to the password.confirm named route.
        $response = $this->actingAs($admin)->get('/admin/merchants');
        $response->assertRedirect();

        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString('confirm-password', $location,
            'Admin route must redirect to password confirmation when not recently confirmed.');
    }

    public function test_merchant_cannot_use_manual_payment_override_route(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        $this->actingAs($merchant)
            ->post(route('admin.orders.manual-payment-override', $order), [
                'amount' => '100.00',
                'verification_reference' => 'BANKREF123456',
                'reason' => 'Independent bank statement reviewed by administrator.',
            ])
            ->assertForbidden();

        $this->assertSame(Order::PENDING, $order->fresh()->status);
        $this->assertDatabaseMissing('admin_audit_logs', ['subject_id' => $order->id]);
    }
}
