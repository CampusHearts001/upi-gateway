<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use App\Support\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookReplayProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->merchant = User::create([
            'name' => 'Alice Merchant',
            'business_name' => 'Alice Store',
            'email' => 'alice@example.com',
            'password' => 'password123',
            'webhook_url' => 'https://example.com/webhook',
        ]);
        $this->merchant->regenerateApiCredentials();
        $this->merchant->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'Main',
            'upi_id' => 'alice@okaxis',
            'payee_name' => 'Alice',
            'is_default' => true,
        ]);
    }

    private function createOrder(): Order
    {
        return app(OrderService::class)->create($this->merchant, ['amount' => 150]);
    }

    // ── 1. Webhook Signature Verification Tests ──────────────────────────────

    public function test_valid_signature_with_fresh_timestamp_passes(): void
    {
        $body = json_encode(['event' => 'order.paid', 'event_id' => (string) Str::uuid()]);
        $secret = 'whsec_test_secret_12345';
        $now = time();

        $signed = WebhookSignature::sign($body, $secret, $now);
        $this->assertTrue(WebhookSignature::verify($body, $signed['header'], $secret, 300, $now));
    }

    public function test_invalid_signature_or_wrong_secret_is_rejected(): void
    {
        $body = json_encode(['event' => 'order.paid', 'event_id' => (string) Str::uuid()]);
        $secret = 'whsec_correct_secret';
        $wrongSecret = 'whsec_wrong_secret';
        $now = time();

        $signed = WebhookSignature::sign($body, $secret, $now);
        $this->assertFalse(WebhookSignature::verify($body, $signed['header'], $wrongSecret, 300, $now));

        // Malformed signature value
        $tamperedHeader = "t={$now},v1=deadbeef1234";
        $this->assertFalse(WebhookSignature::verify($body, $tamperedHeader, $secret, 300, $now));
    }

    public function test_expired_timestamp_outside_five_minute_tolerance_is_rejected(): void
    {
        $body = json_encode(['event' => 'order.paid', 'event_id' => (string) Str::uuid()]);
        $secret = 'whsec_test_secret_12345';
        $now = time();
        $expiredTime = $now - 301; // 5 minutes and 1 second ago

        $signed = WebhookSignature::sign($body, $secret, $expiredTime);
        $this->assertFalse(WebhookSignature::verify($body, $signed['header'], $secret, 300, $now));
    }

    public function test_future_timestamp_outside_tolerance_is_rejected(): void
    {
        $body = json_encode(['event' => 'order.paid', 'event_id' => (string) Str::uuid()]);
        $secret = 'whsec_test_secret_12345';
        $now = time();
        $futureTime = $now + 301; // 301 seconds in the future

        $signed = WebhookSignature::sign($body, $secret, $futureTime);
        $this->assertFalse(WebhookSignature::verify($body, $signed['header'], $secret, 300, $now));
    }

    public function test_modified_payload_body_fails_signature_verification(): void
    {
        $body = json_encode(['event' => 'order.paid', 'amount' => 100]);
        $tamperedBody = json_encode(['event' => 'order.paid', 'amount' => 1000]);
        $secret = 'whsec_test_secret_12345';
        $now = time();

        $signed = WebhookSignature::sign($body, $secret, $now);
        $this->assertFalse(WebhookSignature::verify($tamperedBody, $signed['header'], $secret, 300, $now));
    }

    public function test_secret_rotation_old_secret_fails_and_new_secret_verifies(): void
    {
        $body = json_encode(['event' => 'order.paid', 'event_id' => (string) Str::uuid()]);
        $oldSecret = $this->merchant->regenerateWebhookSecret();
        $now = time();

        $signedWithOld = WebhookSignature::sign($body, $oldSecret, $now);
        $this->assertTrue(WebhookSignature::verify($body, $signedWithOld['header'], $oldSecret, 300, $now));

        // Merchant rotates secret
        $newSecret = $this->merchant->regenerateWebhookSecret();
        $this->assertNotSame($oldSecret, $newSecret);

        // Old signature fails against new secret
        $this->assertFalse(WebhookSignature::verify($body, $signedWithOld['header'], $newSecret, 300, $now));

        // Fresh signature with new secret passes
        $signedWithNew = WebhookSignature::sign($body, $newSecret, $now);
        $this->assertTrue(WebhookSignature::verify($body, $signedWithNew['header'], $newSecret, 300, $now));
    }

    public function test_backward_compatibility_fallback_to_api_secret_when_webhook_secret_not_set(): void
    {
        $this->assertNull($this->merchant->webhook_secret);
        $this->assertSame($this->merchant->api_secret, $this->merchant->getLiveWebhookSigningSecret());

        $dedicated = $this->merchant->regenerateWebhookSecret();
        $this->assertSame($dedicated, $this->merchant->getLiveWebhookSigningSecret());
        $this->assertNotSame($this->merchant->api_secret, $this->merchant->getLiveWebhookSigningSecret());
    }

    public function test_send_webhook_dispatches_replay_safe_headers(): void
    {
        $order = $this->createOrder();
        $eventId = (string) Str::uuid();
        $dedicatedSecret = $this->merchant->regenerateWebhookSecret();

        $outbox = WebhookOutbox::create([
            'event_id' => $eventId,
            'transition_key' => (string) Str::uuid(),
            'order_id' => $order->id,
            'merchant_id' => $this->merchant->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'sequence' => 1,
            'payload' => [
                'event_id' => $eventId,
                'event' => 'order.paid',
                'event_sequence' => 1,
                'data' => array_merge($order->toApiArray(), ['environment' => Order::LIVE]),
            ],
            'status' => 'queued',
            'dispatch_attempts' => 1,
        ]);

        Http::fake([
            'https://example.com/webhook' => Http::response(['status' => 'ok'], 200),
        ]);

        $job = new SendWebhook($order, 'order.paid', $eventId, 1);
        $job->handle();

        Http::assertSent(function ($request) use ($dedicatedSecret, $eventId) {
            $hasSig = $request->hasHeader('X-Signature');
            $hasTimestamp = $request->hasHeader('X-Webhook-Timestamp');
            $hasEventId = $request->hasHeader('X-Event-Id');
            $this->assertTrue($hasSig);
            $this->assertTrue($hasTimestamp);
            $this->assertTrue($hasEventId);
            $this->assertFalse($request->hasHeader('X-Signature-Legacy'), 'X-Signature-Legacy must not be dispatched');
            $this->assertSame($eventId, $request->header('X-Event-Id')[0]);

            $sigHeader = $request->header('X-Signature')[0];
            $body = $request->body();

            // Verify with WebhookSignature validator
            $valid = WebhookSignature::verify($body, $sigHeader, $dedicatedSecret, 300);
            $this->assertTrue($valid, 'Request signature must verify against dedicated webhook secret');

            return true;
        });

        $this->assertSame('delivered', $outbox->fresh()->status);
    }

    public function test_replayed_event_deduplication_contract(): void
    {
        // Simulate a merchant receiver:
        $processedEvents = [];
        $secret = $this->merchant->regenerateWebhookSecret();
        $eventId = (string) Str::uuid();
        $body = json_encode(['event_id' => $eventId, 'event' => 'order.paid', 'client_order_id' => 'INV100']);

        // First delivery:
        $signed = WebhookSignature::sign($body, $secret);
        $this->assertTrue(WebhookSignature::verify($body, $signed['header'], $secret));
        if (! in_array($eventId, $processedEvents, true)) {
            $processedEvents[] = $eventId;
            $fulfillCount = 1;
        }

        // Replayed delivery (e.g. network retry or replay attempt within 5-min window):
        $this->assertTrue(WebhookSignature::verify($body, $signed['header'], $secret));
        if (! in_array($eventId, $processedEvents, true)) {
            $fulfillCount++;
        }

        $this->assertSame(1, $fulfillCount, 'Order must be fulfilled only once despite replayed event');
    }

    public function test_legacy_only_body_signature_is_strictly_rejected(): void
    {
        $body = json_encode(['event' => 'order.paid', 'event_id' => (string) Str::uuid()]);
        $secret = 'whsec_test_secret_12345';
        $legacyHmac = hash_hmac('sha256', $body, $secret);

        // Verification must return false because timestamped format t=...,v1=... is missing
        $this->assertFalse(
            WebhookSignature::verify($body, $legacyHmac, $secret),
            'Legacy body-only HMAC signature must be strictly rejected'
        );
    }

    public function test_missing_and_malformed_timestamped_signatures_are_rejected(): void
    {
        $body = json_encode(['event' => 'order.paid', 'event_id' => (string) Str::uuid()]);
        $secret = 'whsec_test_secret_12345';
        $now = time();
        $validSig = hash_hmac('sha256', "{$now}.{$body}", $secret);

        $malformedCases = [
            '',                                    // empty
            '   ',                                 // whitespace
            null,                                  // null
            "t={$now}",                            // missing v1
            "v1={$validSig}",                      // missing t
            "t=abc,v1={$validSig}",                // non-numeric timestamp
            "t=-100,v1={$validSig}",               // negative timestamp
            "t=0,v1={$validSig}",                  // zero timestamp
            'invalid_format_string',               // garbage string
            "t={$now},v2={$validSig}",             // wrong version token
            "t={$now},v1={$validSig},unexpected",  // malformed non-pair segment
            "t={$now},v1={$validSig},foo=bar",     // unexpected parameter
            "t=,v1={$validSig}",                   // empty timestamp value
            "t={$now},v1=",                        // empty signature value
            "=,v1={$validSig}",                    // empty key and value
            "t={$now},,v1={$validSig}",            // empty segment (double comma)
            "t={$now},v1={$validSig},",            // trailing comma
            ",t={$now},v1={$validSig}",            // leading comma
            "t={$now}=extra,v1={$validSig}",       // multiple equals signs
        ];

        foreach ($malformedCases as $header) {
            $this->assertFalse(
                WebhookSignature::verify($body, $header, $secret, 300, $now),
                "Malformed signature header '{$header}' must be rejected"
            );
        }
    }

    public function test_live_and_sandbox_webhook_secrets_remain_isolated(): void
    {
        $body = json_encode(['event' => 'order.paid', 'event_id' => (string) Str::uuid()]);
        $now = time();

        $liveSecret = $this->merchant->regenerateWebhookSecret();
        $sandboxSecret = $this->merchant->regenerateSandboxWebhookSecret();

        $this->assertNotSame($liveSecret, $sandboxSecret);

        $liveSigned = WebhookSignature::sign($body, $liveSecret, $now);
        $sandboxSigned = WebhookSignature::sign($body, $sandboxSecret, $now);

        // Live signature verified with live secret passes
        $this->assertTrue(WebhookSignature::verify($body, $liveSigned['header'], $liveSecret, 300, $now));

        // Live signature verified with sandbox secret fails
        $this->assertFalse(WebhookSignature::verify($body, $liveSigned['header'], $sandboxSecret, 300, $now));

        // Sandbox signature verified with sandbox secret passes
        $this->assertTrue(WebhookSignature::verify($body, $sandboxSigned['header'], $sandboxSecret, 300, $now));

        // Sandbox signature verified with live secret fails
        $this->assertFalse(WebhookSignature::verify($body, $sandboxSigned['header'], $liveSecret, 300, $now));

        // Also test API secret fallback isolation (when webhook secrets are not generated)
        $unconfiguredMerchant = User::create([
            'name' => 'Bob',
            'business_name' => 'Bob Store',
            'email' => 'bob@example.com',
            'password' => 'password123',
        ]);
        $unconfiguredMerchant->regenerateApiCredentials();
        $unconfiguredMerchant->regenerateSandboxApiCredentials();

        $fallbackLive = $unconfiguredMerchant->getLiveWebhookSigningSecret();
        $fallbackSandbox = $unconfiguredMerchant->getSandboxWebhookSigningSecret();

        $this->assertSame($unconfiguredMerchant->api_secret, $fallbackLive);
        $this->assertSame($unconfiguredMerchant->sandbox_api_secret, $fallbackSandbox);
        $this->assertNotSame($fallbackLive, $fallbackSandbox);

        $signedFallbackLive = WebhookSignature::sign($body, $fallbackLive, $now);
        $this->assertTrue(WebhookSignature::verify($body, $signedFallbackLive['header'], $fallbackLive, 300, $now));
        $this->assertFalse(WebhookSignature::verify($body, $signedFallbackLive['header'], $fallbackSandbox, 300, $now));
    }

    public function test_duplicate_parameter_in_header_is_rejected(): void
    {
        $body = json_encode(['event' => 'order.paid']);
        $secret = 'whsec_test_secret_12345';
        $now = time();
        $sig = hash_hmac('sha256', "{$now}.{$body}", $secret);

        // Duplicate t parameter
        $headerWithDupT = "t={$now},v1={$sig},t=".($now + 10);
        $this->assertFalse(WebhookSignature::verify($body, $headerWithDupT, $secret, 300, $now));

        // Duplicate v1 parameter
        $headerWithDupV1 = "t={$now},v1={$sig},v1={$sig}";
        $this->assertFalse(WebhookSignature::verify($body, $headerWithDupV1, $secret, 300, $now));
    }

    public function test_invalid_hex_and_length_signatures_are_rejected(): void
    {
        $body = json_encode(['event' => 'order.paid']);
        $secret = 'whsec_test_secret_12345';
        $now = time();

        // 63 chars (too short)
        $short = str_repeat('a', 63);
        $this->assertFalse(WebhookSignature::verify($body, "t={$now},v1={$short}", $secret, 300, $now));

        // 65 chars (too long)
        $long = str_repeat('a', 65);
        $this->assertFalse(WebhookSignature::verify($body, "t={$now},v1={$long}", $secret, 300, $now));

        // 64 chars but non-hex
        $nonHex = str_repeat('z', 64);
        $this->assertFalse(WebhookSignature::verify($body, "t={$now},v1={$nonHex}", $secret, 300, $now));
    }

    public function test_parse_header_strictly_rejects_malformed_segments_empty_values_unexpected_params_and_duplicates(): void
    {
        // Valid headers
        $this->assertSame(['t' => '1700000000', 'v1' => 'abcdef'], WebhookSignature::parseHeader('t=1700000000,v1=abcdef'));
        $this->assertSame(['v1' => 'abcdef', 't' => '1700000000'], WebhookSignature::parseHeader('v1=abcdef,t=1700000000'));

        // Malformed non-empty segments
        $this->assertSame([], WebhookSignature::parseHeader('t=123,v1=abc,unexpected'));
        $this->assertSame([], WebhookSignature::parseHeader('unexpected,t=123,v1=abc'));

        // Unexpected parameters
        $this->assertSame([], WebhookSignature::parseHeader('t=123,v1=abc,foo=bar'));
        $this->assertSame([], WebhookSignature::parseHeader('t=123,v1=abc,v2=xyz'));

        // Empty parameter values or keys
        $this->assertSame([], WebhookSignature::parseHeader('t=,v1=abc'));
        $this->assertSame([], WebhookSignature::parseHeader('t=123,v1='));
        $this->assertSame([], WebhookSignature::parseHeader('=123,v1=abc'));
        $this->assertSame([], WebhookSignature::parseHeader('=,v1=abc'));

        // Empty segments / duplicate commas / boundaries
        $this->assertSame([], WebhookSignature::parseHeader('t=123,,v1=abc'));
        $this->assertSame([], WebhookSignature::parseHeader('t=123,v1=abc,'));
        $this->assertSame([], WebhookSignature::parseHeader(',t=123,v1=abc'));

        // Multiple equals signs
        $this->assertSame([], WebhookSignature::parseHeader('t=123=456,v1=abc'));
        $this->assertSame([], WebhookSignature::parseHeader('t=123,v1=abc=def'));

        // Duplicate keys
        $this->assertSame([], WebhookSignature::parseHeader('t=123,v1=abc,t=456'));
        $this->assertSame([], WebhookSignature::parseHeader('t=123,v1=abc,v1=def'));
    }
}
