<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use App\Services\WebhookRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookRetentionLockingTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Order $order;

    private WebhookRetentionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->merchant = User::create(['name' => 'Locking Test', 'email' => 'locking@example.com', 'password' => 'password12345']);
        $this->merchant->regenerateApiCredentials();
        $this->merchant->update(['webhook_url' => 'https://merchant.example/hook']);
        $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'Locking Test', 'payee_name' => 'Test', 'upi_id' => 'test@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);
        $this->order = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
        $this->service = app(WebhookRetentionService::class);
    }

    public function test_a1_sql_candidate_exclusion_filters_out_non_delivered_records(): void
    {
        // Outbox event in queued state
        WebhookOutbox::create([
            'event_id' => 'uuid-queued-candidate',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => ['order_id' => $this->order->order_no],
            'status' => 'queued',
            'delivered_at' => null,
            'available_at' => now()->subMinutes(10),
        ]);

        $offloaded = $this->service->offloadDeliveredPayloads(10);

        // Excluded strictly at SQL level (0 offloaded)
        $this->assertSame(0, $offloaded);
    }

    public function test_a2_cache_mutex_contention_skips_event_while_mutex_is_held(): void
    {
        $event = WebhookOutbox::create([
            'event_id' => 'uuid-mutex-held',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => ['order_id' => $this->order->order_no],
            'status' => 'delivered',
            'delivered_at' => now()->subDays(35),
        ]);

        // Simulated worker holding cache lock
        $workerLock = Cache::lock('webhook-delivery:uuid-mutex-held', 10);
        $this->assertTrue($workerLock->get());

        try {
            $offloaded = $this->service->offloadDeliveredPayloads(10);
            $this->assertSame(0, $offloaded);
            $this->assertNotNull($event->fresh()->payload);
        } finally {
            $workerLock->release();
        }

        // Once lock is released, pruner succeeds
        $offloadedAfter = $this->service->offloadDeliveredPayloads(10);
        $this->assertSame(1, $offloadedAfter);
        $this->assertNull($event->fresh()->payload);
    }

    public function test_b_cross_tier_reconciliation_discovers_archived_success_log(): void
    {
        $event = WebhookOutbox::create([
            'event_id' => 'uuid-reconcile-archived',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => [
                'event_id' => 'uuid-reconcile-archived',
                'event_sequence' => 1,
                'event' => 'order.paid',
                'data' => $this->order->toApiArray(),
            ],
            'status' => 'queued',
            'dispatch_attempts' => 1,
            'delivery_attempts' => 1,
            'delivered_at' => null,
        ]);

        // Place successful delivery in archived_webhook_logs (active table is empty)
        DB::table('archived_webhook_logs')->insert([
            'id' => 999,
            'order_id' => $this->order->id,
            'event' => 'order.paid',
            'event_id' => 'uuid-reconcile-archived',
            'url' => 'https://merchant.example/hook',
            'payload' => json_encode(['foo' => 'bar']),
            'response_code' => 200,
            'success' => true,
            'attempt' => 1,
            'created_at' => now()->subDays(35),
            'updated_at' => now()->subDays(35),
        ]);

        Http::fake();

        $job = new SendWebhook($this->order, 'order.paid', 'uuid-reconcile-archived', 1);
        $job->handle();

        // 0 HTTP calls sent
        Http::assertNothingSent();
        // Outbox marked delivered via cross-tier reconciliation
        $this->assertSame('delivered', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->delivered_at);
    }
}
