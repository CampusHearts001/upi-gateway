<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\WebhookOutbox;
use App\Support\SafeUrl;
use App\Support\WebhookSignature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * POSTs an order event to the merchant's webhook URL, signed with
 * HMAC-SHA256 of the raw body using the merchant's API secret
 * (header: X-Signature). Retried with backoff on failure.
 *
 * SSRF mitigations applied here:
 *   1. URL is re-validated by SafeUrl at send time (guards against DNS rebinding).
 *   2. The connection is pinned to the validated IP via CURLOPT_RESOLVE so curl
 *      cannot re-resolve the hostname to a different address mid-flight.
 *   3. Redirects are disabled (withoutRedirecting()) to prevent redirect chains
 *      that land on internal addresses.
 *   4. The response body is never stored; only the status code (or a short error
 *      label) is persisted in webhook_logs.
 */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public ?array $legacySnapshot = null;

    public ?string $outboxEventId = null;

    // A default also keeps jobs serialized before generation fencing readable.
    public ?int $publicationGeneration = null;

    public function __construct(public Order $order, public string $event, ?string $outboxEventId = null, ?int $publicationGeneration = null)
    {
        $this->outboxEventId = $outboxEventId;
        $this->publicationGeneration = $publicationGeneration;
        // Compatibility for direct callers: capture now, never at worker time.
        // Jobs serialized by older application versions have no snapshot and
        // must be drained before upgrade or investigated via failed_jobs.
        if ($outboxEventId === null) {
            $this->legacySnapshot = ['event_id' => (string) Str::uuid(), 'event' => $event, 'data' => $order->toApiArray()];
        }
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(): void
    {
        if ($this->outboxEventId === null) {
            if ($this->legacySnapshot === null) {
                throw new RuntimeException('Legacy webhook job has no immutable snapshot; operator review is required.');
            }
            $this->deliver(null, $this->legacySnapshot);

            return;
        }

        $lock = Cache::lock('webhook-delivery:'.$this->outboxEventId, 60);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }
        try {
            $outbox = WebhookOutbox::where('event_id', $this->outboxEventId)->firstOrFail();
            if ($outbox->delivered_at !== null) {
                return;
            }
            if ($outbox->order_id !== $this->order->id) {
                throw new RuntimeException('Webhook event order mismatch.');
            }
            if (! in_array($outbox->environment, [Order::LIVE, Order::SANDBOX], true)
                || ($outbox->payload['event_id'] ?? null) !== $outbox->event_id
                || ($outbox->payload['event'] ?? null) !== $outbox->event
                || ($outbox->payload['event_sequence'] ?? null) !== $outbox->sequence
                || ($outbox->payload['data']['environment'] ?? null) !== $outbox->environment) {
                $this->block($outbox, 'invalid_snapshot');

                return;
            }
            $this->event = $outbox->event;
            $outbox->increment('delivery_attempts');
            $this->deliver($outbox, $outbox->payload);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->outboxEventId !== null && $this->publicationGeneration !== null) {
            WebhookOutbox::where('event_id', $this->outboxEventId)->whereNull('delivered_at')
                ->where('dispatch_attempts', $this->publicationGeneration)
                ->whereNull('claim_token')->whereNull('claimed_at')
                ->where('status', 'queued')->update([
                    'status' => 'failed', 'last_error' => 'delivery_exhausted',
                    'available_at' => now()->addSeconds(config('webhooks.failed_retry_seconds')),
                ]);
        }
    }

    private function markDelivered(?WebhookOutbox $outbox): void
    {
        if ($outbox !== null) {
            WebhookOutbox::whereKey($outbox->id)->whereNull('delivered_at')->update([
                'status' => 'delivered', 'delivered_at' => now(), 'available_at' => null,
                'last_error' => null, 'claim_token' => null, 'claimed_at' => null,
            ]);
        }
    }

    private function block(?WebhookOutbox $outbox, string $reason): void
    {
        if ($outbox !== null) {
            WebhookOutbox::whereKey($outbox->id)->whereNull('delivered_at')->update([
                'status' => 'blocked', 'last_error' => $reason, 'available_at' => null,
                'claim_token' => null, 'claimed_at' => null,
            ]);
        }
    }

    private function deliver(?WebhookOutbox $outbox, array $payload): void
    {
        // Receipt is historical fact, independent of today's destination or
        // credentials. Reconcile even a previously blocked event before
        // considering whether another HTTP attempt is allowed.
        if ($this->order->webhookLogs()
            ->when($outbox !== null, fn ($q) => $q->where('event_id', $outbox->event_id),
                fn ($q) => $q->where('event', $this->event))
            ->where('success', true)->exists()) {
            $this->markDelivered($outbox);

            return;
        }
        if ($outbox?->status === 'blocked') {
            return;
        }

        $merchant = $this->order->user;
        $sandbox = ($outbox?->environment ?? $payload['data']['environment']) === Order::SANDBOX;
        $webhookUrl = $sandbox ? $merchant->sandbox_webhook_url : $merchant->webhook_url;
        $signingSecret = $sandbox ? $merchant->getSandboxWebhookSigningSecret() : $merchant->getLiveWebhookSigningSecret();

        if (! $webhookUrl || ! $signingSecret) {
            $this->block($outbox, 'configuration_missing');

            return;
        }

        if ($sandbox && $merchant->webhook_url
            && hash_equals(rtrim($merchant->webhook_url, '/'), rtrim($webhookUrl, '/'))) {
            $this->block($outbox, 'environment_url_collision');

            return;
        }

        // ── Re-validate URL at send time (DNS rebinding defence) ──────────────
        try {
            $safe = app(SafeUrl::class)->check($webhookUrl);
        } catch (ValidationException) {
            // URL is now unsafe (e.g. DNS rebind). Log and bail — do NOT retry.
            $this->order->webhookLogs()->create([
                'event' => $this->event,
                'event_id' => $payload['event_id'],
                'url' => $webhookUrl,
                'payload' => $payload,
                'response_code' => null,
                'response_body' => null,
                'success' => false,
                'attempt' => $this->attempts(),
                'error_label' => 'blocked_url',
            ]);
            $this->block($outbox, 'blocked_url');

            // Return without throwing → queue does NOT retry this job.
            return;
        }

        // ── Build and sign the payload with replay protection ─────────────────
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signed = WebhookSignature::sign($body, $signingSecret);

        // ── CURLOPT_RESOLVE pins the connection to the validated IP ────────────
        // Format: "hostname:port:ip" — prevents curl from re-resolving.
        $resolveEntry = "{$safe['host']}:{$safe['port']}:{$safe['ips'][0]}";

        $code = null;
        $errorLabel = null;

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withHeaders([
                    'X-Signature' => $signed['header'],
                    'X-Signature-Timestamp' => (string) $signed['timestamp'],
                    'X-Signature-Legacy' => $signed['legacy_signature'],
                    'X-Webhook-Timestamp' => (string) $signed['timestamp'],
                    'X-Event' => $this->event,
                    'X-Event-Id' => $payload['event_id'],
                ])
                ->withBody($body, 'application/json')
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$resolveEntry]]])
                ->post($webhookUrl);

            $code = $response->status();

            if ($code >= 500) {
                $errorLabel = 'http_'.$code;
            }
        } catch (Throwable $e) {
            // Distinguish timeout from other network errors for the log label.
            $lower = strtolower($e->getMessage());
            if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
                $errorLabel = 'timeout';
            } else {
                $errorLabel = 'network_error';
            }
        }

        $success = $code !== null && $code >= 200 && $code < 300;

        // Store only the status code and a short error label — never the body.
        $this->order->webhookLogs()->create([
            'event' => $this->event,
            'event_id' => $payload['event_id'],
            'url' => $webhookUrl,
            'payload' => $payload,
            'response_code' => $code,
            'response_body' => null,
            'success' => $success,
            'attempt' => $this->attempts(),
            'error_label' => $errorLabel,
        ]);

        if (! $success) {
            if ($outbox !== null) {
                WebhookOutbox::whereKey($outbox->id)->whereNull('delivered_at')->update(['last_error' => $errorLabel ?? 'http_'.$code]);
            }
            throw new RuntimeException(
                'Webhook delivery failed with '.($code ?? 'no response')
            );
        }
        $this->markDelivered($outbox);
    }
}
