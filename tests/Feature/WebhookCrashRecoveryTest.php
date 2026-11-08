<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookLog;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookCrashRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->merchant = User::create(['name' => 'Crash Test', 'email' => 'crash@example.com', 'password' => 'password12345']);
        $this->merchant->regenerateApiCredentials();
        $this->merchant->update(['webhook_url' => 'https://merchant.example/hook']);
        $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'Crash Test', 'payee_name' => 'Test', 'upi_id' => 'test@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);
        $this->order = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
    }

    public function test_crash_recovery_backs_off_under_unexpired_ttl_and_reconciles_post_wall_clock_expiry(): void
    {
        // 1 second real lock TTL
        config(['webhooks.delivery_lock_seconds' => 1]);

        $event = WebhookOutbox::create([
            'event_id' => 'uuid-crash-recovery',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => [
                'event_id' => 'uuid-crash-recovery',
                'event_sequence' => 1,
                'event' => 'order.paid',
                'data' => $this->order->toApiArray(),
            ],
            'status' => 'queued',
            'dispatch_attempts' => 1,
            'delivery_attempts' => 1,
            'delivered_at' => null,
        ]);

        // Simulate Worker 1 holding the lock with 1s TTL, successfully delivering and writing webhook_logs,
        // then crashing (SIGKILL) without releasing the lock or updating webhook_outbox.delivered_at.
        $lock = Cache::lock('webhook-delivery:uuid-crash-recovery', 1);
        $this->assertTrue($lock->get());

        WebhookLog::create([
            'order_id' => $this->order->id,
            'event' => 'order.paid',
            'event_id' => 'uuid-crash-recovery',
            'url' => 'https://merchant.example/hook',
            'payload' => $event->payload,
            'response_code' => 200,
            'response_body' => null,
            'success' => true,
            'attempt' => 1,
        ]);

        // HTTP fake asserting 0 additional HTTP requests throughout recovery
        Http::fake();

        // 1. Immediate Recovery Attempt (< 100ms): Lock has NOT expired.
        // Worker must back off without sending HTTP or mutating outbox.
        $recoveryJobImmediate = new SendWebhook($this->order, 'order.paid', 'uuid-crash-recovery', 1);
        $recoveryJobImmediate->handle();

        Http::assertNothingSent();
        $this->assertNull($event->fresh()->delivered_at);
        $this->assertSame('queued', $event->fresh()->status);

        // 2. Real wall-clock sleep for 1.1 seconds so the real cache TTL expires naturally (not Carbon::setTestNow).
        usleep(1100000);

        // 3. Post-Expiry Recovery Attempt:
        // The lock has expired. Recovery worker acquires the lock, detects the historical success log,
        // and reconciles outbox to delivered with zero HTTP requests!
        $recoveryJobPostExpiry = new SendWebhook($this->order, 'order.paid', 'uuid-crash-recovery', 1);
        $recoveryJobPostExpiry->handle();

        Http::assertNothingSent(); // Still zero HTTP calls!
        $fresh = $event->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
    }
}
