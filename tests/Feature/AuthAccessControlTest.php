<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectIfInstalled;
use App\Http\Middleware\RedirectToInstall;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Authentication, API security, and access control tests.
 *
 * Covers:
 *  - Valid / invalid / revoked API credentials
 *  - Suspended merchant API and web access
 *  - Merchant A cannot access Merchant B's orders, accounts, settings
 *  - Admin role enforcement (merchants cannot reach admin routes)
 *  - Password confirm rate limiting
 *  - EnsureActive CSRF token regeneration
 *  - Installer admin password policy
 *  - DB error message does not leak credentials
 *  - API secret never exposed in responses
 *  - Login brute-force lockout
 */
class AuthAccessControlTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function merchant(array $attrs = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Ravi',
            'business_name' => 'Ravi Store',
            'email' => 'ravi@example.com',
            'password' => 'password123',
        ], $attrs));
        $user->regenerateApiCredentials();
        $user->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'GPay',
            'upi_id' => 'ravi@okaxis',
            'payee_name' => 'Ravi',
            'is_default' => true,
        ]);

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

    private function apiHeaders(User $user): array
    {
        return [
            'X-Api-Key' => $user->api_key,
            'X-Api-Secret' => $user->api_secret,
            'Accept' => 'application/json',
        ];
    }

    private function order(User $merchant): Order
    {
        return app(OrderService::class)->create($merchant, [
            'amount' => 100,
            'client_order_id' => 'T-'.uniqid(),
        ]);
    }

    // ── 1. API credential validation ──────────────────────────────────────────

    public function test_valid_api_credentials_are_accepted(): void
    {
        $merchant = $this->merchant();

        $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'VALID-1',
        ], $this->apiHeaders($merchant))->assertCreated();
    }

    public function test_wrong_api_key_is_rejected(): void
    {
        $this->merchant();

        $this->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'X'], [
            'X-Api-Key' => 'key_wrongkey',
            'X-Api-Secret' => 'sec_wrongsecret',
            'Accept' => 'application/json',
        ])->assertUnauthorized();
    }

    public function test_correct_key_wrong_secret_is_rejected(): void
    {
        $merchant = $this->merchant();

        $this->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'X'], [
            'X-Api-Key' => $merchant->api_key,
            'X-Api-Secret' => 'sec_totallywrong',
            'Accept' => 'application/json',
        ])->assertUnauthorized();
    }

    public function test_missing_api_headers_are_rejected(): void
    {
        $this->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'X'])
            ->assertUnauthorized();
    }

    public function test_revoked_api_credentials_are_rejected(): void
    {
        $merchant = $this->merchant();
        $oldKey = $merchant->api_key;
        $oldSecret = $merchant->api_secret;

        // Regenerate — old credentials must stop working immediately.
        $merchant->regenerateApiCredentials();

        $this->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'REV-1'], [
            'X-Api-Key' => $oldKey,
            'X-Api-Secret' => $oldSecret,
            'Accept' => 'application/json',
        ])->assertUnauthorized();
    }

    public function test_new_credentials_work_after_regeneration(): void
    {
        $merchant = $this->merchant();
        $merchant->regenerateApiCredentials();

        $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'REV-2',
        ], $this->apiHeaders($merchant))->assertCreated();
    }

    // ── 2. Suspended merchant access ─────────────────────────────────────────

    public function test_suspended_merchant_cannot_use_api(): void
    {
        $merchant = $this->merchant();
        $merchant->update(['is_active' => false]);

        $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'SUSP-1',
        ], $this->apiHeaders($merchant))->assertForbidden();
    }

    public function test_suspended_merchant_is_logged_out_on_web(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($merchant);

        // Disable the account while the session is active.
        $merchant->update(['is_active' => false]);

        // Next authenticated web request must redirect to login.
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    // ── 3. Merchant isolation — orders ───────────────────────────────────────

    public function test_merchant_b_cannot_view_merchant_a_order(): void
    {
        $merchantA = $this->merchant();
        $order = $this->order($merchantA);

        $merchantB = User::create([
            'name' => 'B', 'email' => 'b@example.com', 'password' => 'password123',
        ]);

        $this->actingAs($merchantB)
            ->get(route('orders.show', $order))
            ->assertNotFound();
    }

    public function test_merchant_b_cannot_mark_merchant_a_order_paid(): void
    {
        $merchantA = $this->merchant();
        $order = $this->order($merchantA);

        $merchantB = User::create([
            'name' => 'B', 'email' => 'b@example.com', 'password' => 'password123',
        ]);

        $this->actingAs($merchantB)
            ->post(route('orders.mark-paid', $order), ['utr' => '123456789012'])
            ->assertNotFound();

        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_merchant_b_cannot_recheck_merchant_a_order(): void
    {
        $merchantA = $this->merchant();
        $order = $this->order($merchantA);

        $merchantB = User::create([
            'name' => 'B', 'email' => 'b@example.com', 'password' => 'password123',
        ]);

        $this->actingAs($merchantB)
            ->post(route('orders.recheck', $order))
            ->assertNotFound();
    }

    public function test_merchant_b_cannot_fetch_merchant_a_order_via_api(): void
    {
        $merchantA = $this->merchant();
        $order = $this->order($merchantA);

        $merchantB = User::create([
            'name' => 'B', 'email' => 'b@example.com', 'password' => 'password123',
        ]);
        $merchantB->regenerateApiCredentials();
        $merchantB->paymentAccounts()->create([
            'provider' => 'manual', 'label' => 'X', 'upi_id' => 'b@upi',
            'payee_name' => 'B', 'is_default' => true,
        ]);

        $this->getJson('/api/v1/orders/'.$order->order_no, $this->apiHeaders($merchantB))
            ->assertNotFound();
    }

    // ── 4. Merchant isolation — payment accounts ──────────────────────────────

    public function test_merchant_b_cannot_toggle_merchant_a_account(): void
    {
        $merchantA = $this->merchant();
        $account = $merchantA->paymentAccounts()->first();

        $merchantB = User::create([
            'name' => 'B', 'email' => 'b@example.com', 'password' => 'password123',
        ]);

        $this->actingAs($merchantB)
            ->post(route('accounts.toggle', $account))
            ->assertForbidden();

        // Account must remain unchanged.
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_merchant_b_cannot_update_limit_on_merchant_a_account(): void
    {
        $merchantA = $this->merchant();
        $account = $merchantA->paymentAccounts()->first();

        $merchantB = User::create([
            'name' => 'B', 'email' => 'b@example.com', 'password' => 'password123',
        ]);

        $this->actingAs($merchantB)
            ->post(route('accounts.limit', $account), ['daily_limit' => 1])
            ->assertForbidden();
    }

    public function test_merchant_b_cannot_delete_merchant_a_account(): void
    {
        $merchantA = $this->merchant();
        $account = $merchantA->paymentAccounts()->first();

        $merchantB = User::create([
            'name' => 'B', 'email' => 'b@example.com', 'password' => 'password123',
        ]);

        $this->actingAs($merchantB)
            ->delete(route('accounts.destroy', $account))
            ->assertForbidden();

        $this->assertDatabaseHas('payment_accounts', ['id' => $account->id]);
    }

    public function test_merchant_b_cannot_set_merchant_a_account_as_default(): void
    {
        $merchantA = $this->merchant();
        $account = $merchantA->paymentAccounts()->first();

        $merchantB = User::create([
            'name' => 'B', 'email' => 'b@example.com', 'password' => 'password123',
        ]);

        $this->actingAs($merchantB)
            ->post(route('accounts.default', $account))
            ->assertForbidden();
    }

    // ── 5. Admin access control ───────────────────────────────────────────────

    public function test_merchant_cannot_access_admin_merchants_page(): void
    {
        $merchant = $this->merchant();

        $this->actingAs($merchant)
            ->get('/admin/merchants')
            ->assertForbidden();
    }

    public function test_merchant_cannot_toggle_another_merchant_via_admin_route(): void
    {
        $merchantA = $this->merchant();
        $merchantB = User::create([
            'name' => 'B', 'email' => 'b@example.com', 'password' => 'password123',
        ]);

        $this->actingAs($merchantA)
            ->post("/admin/merchants/{$merchantB->id}/toggle")
            ->assertForbidden();

        $this->assertTrue($merchantB->fresh()->is_active);
    }

    public function test_merchant_cannot_access_admin_orders_page(): void
    {
        $merchant = $this->merchant();

        $this->actingAs($merchant)
            ->get('/admin/orders')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_access_admin_routes(): void
    {
        $this->get('/admin/merchants')->assertRedirect(route('login'));
        $this->get('/admin/orders')->assertRedirect(route('login'));
    }

    // ── 6. API secret never exposed in responses ──────────────────────────────

    public function test_api_secret_not_exposed_in_order_create_response(): void
    {
        $merchant = $this->merchant();

        $response = $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'SEC-1',
        ], $this->apiHeaders($merchant));

        $body = $response->getContent();
        $this->assertStringNotContainsString($merchant->api_secret, $body);
        $this->assertStringNotContainsString('api_secret', $body);
    }

    public function test_api_secret_not_exposed_in_accounts_response(): void
    {
        $merchant = $this->merchant();

        $response = $this->getJson('/api/v1/accounts', $this->apiHeaders($merchant));

        $body = $response->getContent();
        $this->assertStringNotContainsString($merchant->api_secret, $body);
        $this->assertStringNotContainsString('api_secret', $body);
        // Credentials (MID, keys) must not appear in the accounts list.
        $this->assertStringNotContainsString('credentials', $body);
    }

    // ── 7. Password confirm rate limiting ─────────────────────────────────────

    public function test_confirm_password_is_rate_limited(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($merchant);

        $key = 'confirm-password:'.$merchant->id;

        // Exhaust the 5-attempt allowance.
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->post('/confirm-password', ['password' => 'password123'])
            ->assertSessionHasErrors('password');

        $errors = session('errors');
        $this->assertStringContainsString('Too many attempts', $errors->first('password'));
    }

    public function test_confirm_password_clears_rate_limit_on_success(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($merchant);

        $key = 'confirm-password:'.$merchant->id;
        RateLimiter::hit($key, 60); // one failure

        // Correct password clears the limiter.
        $this->post('/confirm-password', ['password' => 'password123'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, RateLimiter::attempts($key));
    }

    // ── 8. Login brute-force lockout ──────────────────────────────────────────

    public function test_login_lockout_after_10_failures(): void
    {
        $merchant = $this->merchant();
        $lockKey = 'login_lock:'.sha1($merchant->email.'|127.0.0.1');
        $failKey = 'login_fails:'.sha1($merchant->email.'|127.0.0.1');

        Cache::put($failKey, 10, 900);
        Cache::put($lockKey, true, 900);

        $this->post('/login', ['email' => $merchant->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->first('email')
        );
    }

    public function test_concurrent_first_failures_increment_failure_counter_atomically(): void
    {
        $merchant = $this->merchant();
        $ip = '198.51.100.77';
        $failKey = 'login_fails:'.sha1($merchant->email.'|'.$ip);

        Cache::forget($failKey);

        // Process 1 fails login: sets failure count to 1 via Cache::add()
        $this->withoutMiddleware(ThrottleRequests::class)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => $merchant->email, 'password' => 'wrong-pass-1'])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, (int) Cache::get($failKey));

        // Process 2 fails login concurrently: Cache::add() returns false because key exists,
        // and it atomically increments to 2 via Cache::increment()
        $this->withoutMiddleware(ThrottleRequests::class)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => $merchant->email, 'password' => 'wrong-pass-2'])
            ->assertSessionHasErrors('email');

        $this->assertSame(2, (int) Cache::get($failKey));
    }

    public function test_consecutive_failed_logins_trigger_lockout_without_prepopulated_cache(): void
    {
        $merchant = $this->merchant();
        $ip = '198.51.100.42';

        for ($i = 1; $i <= 9; $i++) {
            $this->withoutMiddleware(ThrottleRequests::class)
                ->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/login', ['email' => $merchant->email, 'password' => 'wrong-pass-'.$i])
                ->assertSessionHasErrors('email');

            $this->assertStringContainsString(
                'These credentials do not match our records',
                session('errors')->first('email')
            );
            RateLimiter::clear('login:'.sha1($merchant->email.'|'.$ip));
        }

        // 10th failure triggers the lockout
        $this->withoutMiddleware(ThrottleRequests::class)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => $merchant->email, 'password' => 'wrong-pass-10'])
            ->assertSessionHasErrors('email');

        // Subsequent attempt with correct password is now blocked by the 15-minute lockout
        $this->withoutMiddleware(ThrottleRequests::class)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => $merchant->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->first('email')
        );

        // A different merchant from the same IP is NOT locked out
        $otherMerchant = User::create([
            'name' => 'Different Merchant',
            'business_name' => 'Different Store',
            'email' => 'different@example.com',
            'password' => 'password123',
        ]);
        $this->withoutMiddleware(ThrottleRequests::class)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => $otherMerchant->email, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_route_level_throttle_blocks_excessive_rapid_login_attempts(): void
    {
        $ip = '198.51.100.99';
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/login', ['email' => 'rapid'.$i.'@example.com', 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }

        // 11th rapid attempt within the same minute is blocked with 429 Too Many Requests
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => 'rapid11@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_login_lockout_for_one_email_does_not_lock_another_email_at_same_ip(): void
    {
        $locked = $this->merchant();
        $other = User::create([
            'name' => 'Other Merchant',
            'business_name' => 'Other Store',
            'email' => 'other@example.com',
            'password' => 'password123',
        ]);
        Cache::put('login_lock:'.sha1($locked->email.'|127.0.0.1'), true, 900);

        $this->post('/login', ['email' => $locked->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $other->email, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($other);
    }

    public function test_login_error_does_not_reveal_user_existence(): void
    {
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $msg = session('errors')->first('email');
        $this->assertStringNotContainsStringIgnoringCase('not found', $msg);
        $this->assertStringNotContainsStringIgnoringCase('not registered', $msg);
        $this->assertStringNotContainsStringIgnoringCase('no account', $msg);
    }

    // ── 9. Installer admin password policy ───────────────────────────────────

    public function test_installer_rejects_admin_password_shorter_than_10_chars(): void
    {
        // Test the InstallController validation rules directly by bypassing
        // the RedirectIfInstalled middleware (which blocks the route in test env).
        $this->withoutMiddleware([RedirectIfInstalled::class, RedirectToInstall::class])
            ->withSession(['installer.setup_authorized' => true])
            ->post(route('install.admin.save'), [
                'name' => 'Admin',
                'email' => 'admin@example.com',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }

    public function test_installer_accepts_admin_password_of_10_chars(): void
    {
        $this->withoutMiddleware([RedirectIfInstalled::class, RedirectToInstall::class])
            ->withSession(['installer.setup_authorized' => true])
            ->post(route('install.admin.save'), [
                'name' => 'Admin',
                'email' => 'admin@example.com',
                'password' => 'adminpass10',
                'password_confirmation' => 'adminpass10',
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => 'admin']);
    }

    // ── 10. EnsureActive regenerates CSRF token on forced logout ─────────────

    public function test_ensure_active_regenerates_csrf_token_on_forced_logout(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($merchant);

        // Capture the CSRF token before the account is disabled.
        $tokenBefore = csrf_token();

        $merchant->update(['is_active' => false]);

        // Trigger EnsureActive by making an authenticated request.
        $this->get('/dashboard');

        // After forced logout the session is invalidated, so a new token is issued.
        $tokenAfter = csrf_token();
        $this->assertNotSame($tokenBefore, $tokenAfter,
            'CSRF token must be rotated after forced logout via EnsureActive.');
    }
}
