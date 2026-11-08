<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Support\SafeUrl;
use App\Support\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SSRF protection tests for outbound merchant webhooks.
 *
 * The SafeUrl class accepts an injected resolver callable so DNS can be
 * faked in tests without any real network calls.  We bind a fake resolver
 * via the container before the assertions that need it.
 */
class WebhookSsrfTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Bind a fake DNS resolver that maps hostnames to IP arrays. */
    private function fakeResolver(array $map): void
    {
        $this->app->bind(SafeUrl::class, fn () => new SafeUrl(
            resolver: fn (string $host) => $map[$host] ?? ['93.184.216.34']
        ));
    }

    /** Bind a resolver that always returns the given IPs for any host. */
    private function fakeResolverAlways(array $ips): void
    {
        $this->app->bind(SafeUrl::class, fn () => new SafeUrl(
            resolver: fn (string $host) => $ips
        ));
    }

    private function merchant(?string $webhookUrl = null): User
    {
        $user = User::create([
            'name' => 'Ravi', 'business_name' => 'Ravi Store',
            'email' => 'ravi@example.com', 'password' => 'password123',
        ]);
        $user->regenerateApiCredentials();
        $user->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'Main',
            'upi_id' => 'paytmqr123@paytm', 'payee_name' => 'Ravi Store',
            'credentials' => ['mid' => 'TESTMID123'], 'is_default' => true,
        ]);
        if ($webhookUrl) {
            $user->forceFill(['webhook_url' => $webhookUrl])->save();
        }

        return $user;
    }

    private function order(User $merchant): Order
    {
        return app(OrderService::class)->create($merchant, [
            'amount' => 100,
            'client_order_id' => 'TEST-'.uniqid(),
        ]);
    }

    // ── 1. SettingsController rejects bad URLs at save time ───────────────────

    #[DataProvider('badWebhookUrls')]
    public function test_bad_webhook_url_is_rejected_at_save(string $url, string $description): void
    {
        // Use a resolver that routes any hostname to a private IP so that
        // hostname-based checks (e.g. "localhost") also fail the DNS check
        // in addition to the structural checks.
        $this->fakeResolverAlways(['127.0.0.1']);

        $user = $this->merchant();
        $this->actingAs($user)
            ->post('/settings/webhook', ['webhook_url' => $url])
            ->assertSessionHasErrors('webhook_url', null, 'default');

        $this->assertNull($user->fresh()->webhook_url, "Expected webhook_url to remain null for: {$description}");
    }

    public static function badWebhookUrls(): array
    {
        return [
            'loopback IPv4' => ['http://127.0.0.1/x',                           'loopback IPv4'],
            'localhost hostname' => ['http://localhost',                               'localhost hostname'],
            'EC2 metadata' => ['http://169.254.169.254/latest/meta-data',       'EC2 metadata link-local'],
            'IPv6 loopback' => ['http://[::1]/',                                  'IPv6 loopback literal'],
            'private 10.x' => ['http://10.0.0.5',                               'private 10.x IPv4'],
            'private 192.168.x' => ['http://192.168.1.1',                            'private 192.168.x IPv4'],
            'decimal integer IP' => ['http://2130706433',                             'decimal integer 127.0.0.1'],
            'userinfo in URL' => ['https://user:pass@example.com',                 'credentials in URL'],
            'ftp scheme' => ['ftp://example.com',                             'non-http(s) scheme'],
        ];
    }

    public function test_hostname_resolving_to_private_ip_is_rejected(): void
    {
        $this->fakeResolver(['internal.example.com' => ['10.0.0.1']]);

        $user = $this->merchant();
        $this->actingAs($user)
            ->post('/settings/webhook', ['webhook_url' => 'https://internal.example.com/hook'])
            ->assertSessionHasErrors('webhook_url');

        $this->assertNull($user->fresh()->webhook_url);
    }

    public function test_hostname_with_mixed_public_and_private_a_records_is_rejected(): void
    {
        // Even one private IP in the answer set must cause rejection.
        $this->fakeResolver(['mixed.example.com' => ['93.184.216.34', '10.0.0.1']]);

        $user = $this->merchant();
        $this->actingAs($user)
            ->post('/settings/webhook', ['webhook_url' => 'https://mixed.example.com/hook'])
            ->assertSessionHasErrors('webhook_url');
    }

    public function test_valid_https_url_with_public_ip_is_accepted(): void
    {
        $this->fakeResolver(['example.com' => ['93.184.216.34']]);

        Http::fake(['example.com/*' => Http::response('ok')]);

        $user = $this->merchant();
        $this->actingAs($user)
            ->post('/settings/webhook', ['webhook_url' => 'https://example.com/hook'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('https://example.com/hook', $user->fresh()->webhook_url);
    }

    // ── 2. SendWebhook DNS-rebinding defence ──────────────────────────────────

    public function test_send_webhook_blocked_when_url_resolves_to_private_ip_at_send_time(): void
    {
        // URL was valid at save time; resolver now returns a private IP (rebind).
        $this->fakeResolverAlways(['192.168.1.100']);

        Http::fake();  // no real requests should be made

        $user = $this->merchant('https://example.com/hook');
        $order = $this->order($user);

        (new SendWebhook($order, 'order.paid'))->handle();

        // No HTTP request should have been sent.
        Http::assertNothingSent();

        // A log entry must exist with the blocked_url label.
        $log = $order->webhookLogs()->first();
        $this->assertNotNull($log, 'Expected a webhook_log entry for blocked URL.');
        $this->assertSame('blocked_url', $log->error_label);
        $this->assertFalse($log->success);
        $this->assertNull($log->response_body);
    }

    // ── 3. Redirects are not followed ────────────────────────────────────────

    public function test_redirects_to_internal_url_are_not_followed(): void
    {
        $this->fakeResolverAlways(['93.184.216.34']);

        // Server responds with 302 to an internal address.
        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest']),
        ]);

        $user = $this->merchant('https://example.com/hook');
        $order = $this->order($user);

        // A 302 is a non-2xx failure, so handle() throws to trigger the queue
        // retry mechanism.  The important assertions are: only one HTTP request
        // was made (no redirect follow-through) and the log records the 302.
        try {
            (new SendWebhook($order, 'order.paid'))->handle();
        } catch (\RuntimeException) {
            // Expected: non-2xx causes a RuntimeException so the queue retries.
        }

        // Exactly one request must have been sent (no follow-through).
        Http::assertSentCount(1);

        // Log should record the 302 as failure (not success).
        $log = $order->webhookLogs()->first();
        $this->assertNotNull($log);
        $this->assertSame(302, $log->response_code);
        $this->assertFalse($log->success);
    }

    // ── 4. Response body is never stored ─────────────────────────────────────

    public function test_webhook_log_never_contains_response_body(): void
    {
        $this->fakeResolverAlways(['93.184.216.34']);

        Http::fake([
            'example.com/*' => Http::response('SENSITIVE-INTERNAL-DATA', 200),
        ]);

        $user = $this->merchant('https://example.com/hook');
        $order = $this->order($user);

        (new SendWebhook($order, 'order.paid'))->handle();

        $log = $order->webhookLogs()->first();
        $this->assertNotNull($log);
        $this->assertNull($log->response_body, 'response_body must never be stored.');
    }

    public function test_blocked_url_log_also_has_no_response_body(): void
    {
        $this->fakeResolverAlways(['127.0.0.1']);

        Http::fake();

        $user = $this->merchant('https://example.com/hook');
        $order = $this->order($user);

        (new SendWebhook($order, 'order.paid'))->handle();

        Http::assertNothingSent();
        $this->assertNull($order->webhookLogs()->first()->response_body);
    }

    // ── 5. SendWebhook blocked URL does NOT trigger a retry ──────────────────

    public function test_blocked_url_at_send_time_does_not_throw_and_does_not_retry(): void
    {
        $this->fakeResolverAlways(['10.0.0.1']);

        Http::fake();

        $user = $this->merchant('https://example.com/hook');
        $order = $this->order($user);

        // handle() must return without throwing — so the queue won't retry.
        $threw = false;
        try {
            (new SendWebhook($order, 'order.paid'))->handle();
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse($threw, 'handle() must not throw for a blocked URL (no retry).');
        Http::assertNothingSent();
    }

    // ── 6. Full signed-webhook flow still works (regression) ─────────────────
    //
    // This test focuses specifically on the webhook delivery changes (no body
    // stored, signature preserved).  The full end-to-end Paytm flow is covered
    // by PaymentFlowTest::test_full_paytm_flow_marks_paid_and_sends_signed_webhook.

    public function test_signed_webhook_delivery_null_body_and_correct_signature(): void
    {
        // Default resolver (from TestCase::setUp) returns 93.184.216.34 for any host.
        Http::fake(['merchant.test/*' => Http::response('ok', 200)]);

        $user = $this->merchant('https://merchant.test/hook');
        $order = $this->order($user);

        (new SendWebhook($order, 'order.paid'))->handle();

        // Webhook request must be signed correctly.
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://merchant.test/hook'
            && WebhookSignature::verify($r->body(), $r->header('X-Signature')[0], $user->api_secret)
            && $r['event'] === 'order.paid'
        );

        // Log must show success and must NOT contain a response body.
        $log = $order->webhookLogs()->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->success);
        $this->assertSame(200, $log->response_code);
        $this->assertNull($log->response_body, 'response_body must never be stored.');
    }

    // ── 7. SafeUrl unit cases ─────────────────────────────────────────────────

    public function test_safeurl_rejects_http_when_insecure_disabled(): void
    {
        config(['gateway.webhook_allow_insecure' => false]);
        $this->fakeResolverAlways(['93.184.216.34']);

        $this->expectException(ValidationException::class);
        app(SafeUrl::class)->check('http://example.com/hook');
    }

    public function test_safeurl_allows_http_when_insecure_enabled(): void
    {
        config(['gateway.webhook_allow_insecure' => true]);
        config(['gateway.webhook_allowed_ports' => [443, 80]]);
        $this->fakeResolverAlways(['93.184.216.34']);

        $result = app(SafeUrl::class)->check('http://example.com/hook');
        $this->assertSame('example.com', $result['host']);
        $this->assertSame(80, $result['port']);
    }

    public function test_safeurl_rejects_cgnat_address(): void
    {
        $this->fakeResolverAlways(['100.96.0.1']); // CGNAT 100.64.0.0/10

        $this->expectException(ValidationException::class);
        app(SafeUrl::class)->check('https://example.com/hook');
    }

    public function test_safeurl_rejects_ipv6_unique_local(): void
    {
        $this->fakeResolverAlways(['fd00::1']); // fc00::/7 unique local

        $this->expectException(ValidationException::class);
        app(SafeUrl::class)->check('https://example.com/hook');
    }

    public function test_safeurl_rejects_ipv4_mapped_ipv6_loopback(): void
    {
        // ::ffff:127.0.0.1 should be normalised to 127.0.0.1 and rejected.
        $this->fakeResolverAlways(['::ffff:127.0.0.1']);

        $this->expectException(ValidationException::class);
        app(SafeUrl::class)->check('https://example.com/hook');
    }
}
