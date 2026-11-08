<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use App\Services\WebhookRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebhookOutboxPayloadOffloadTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Order $order;

    private WebhookRetentionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::create(['name' => 'Offload Test', 'email' => 'offload@example.com', 'password' => 'password12345']);
        $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'Offload Test', 'payee_name' => 'Test', 'upi_id' => 'test@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);
        $this->order = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
        $this->service = app(WebhookRetentionService::class);
    }

    public function test_offloads_payload_for_delivered_events_past_cutoff_preserving_skeleton(): void
    {
        $deliveredPast = WebhookOutbox::create([
            'event_id' => 'uuid-delivered-past',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => ['order_id' => $this->order->order_no, 'data' => ['foo' => 'bar']],
            'status' => 'delivered',
            'delivered_at' => now()->subDays(35),
        ]);

        $deliveredRecent = WebhookOutbox::create([
            'event_id' => 'uuid-delivered-recent',
            'order_id' => $this->order->id,
            'sequence' => 2,
            'transition_key' => 'transition:'.$this->order->id.':2',
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => ['order_id' => $this->order->order_no, 'recent' => true],
            'status' => 'delivered',
            'delivered_at' => now()->subDays(5),
        ]);

        $queuedEvent = WebhookOutbox::create([
            'event_id' => 'uuid-queued',
            'order_id' => $this->order->id,
            'sequence' => 3,
            'transition_key' => 'transition:'.$this->order->id.':3',
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => ['order_id' => $this->order->order_no, 'queued' => true],
            'status' => 'queued',
            'delivered_at' => null,
        ]);

        $offloaded = $this->service->offloadDeliveredPayloads(100);

        $this->assertSame(1, $offloaded);

        // Check deliveredPast has payload offloaded to archive table, but canonical skeleton remains
        $refreshedPast = $deliveredPast->fresh();
        $this->assertNull($refreshedPast->payload);
        $this->assertSame('uuid-delivered-past', $refreshedPast->event_id);
        $this->assertSame('paid:'.$this->order->id, $refreshedPast->transition_key);
        $this->assertSame('delivered', $refreshedPast->status);

        $archivedPayload = DB::table('archived_webhook_payloads')->where('event_id', 'uuid-delivered-past')->first();
        $this->assertNotNull($archivedPayload);
        $this->assertStringContainsString('foo', $archivedPayload->payload);

        // Recent and queued events retain their payloads
        $this->assertNotNull($deliveredRecent->fresh()->payload);
        $this->assertNotNull($queuedEvent->fresh()->payload);
    }

    public function test_restore_payload_command_restores_without_altering_status(): void
    {
        $event = WebhookOutbox::create([
            'event_id' => 'uuid-to-restore',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => null,
            'status' => 'blocked',
            'delivered_at' => null,
            'last_error' => 'configuration_missing',
        ]);

        DB::table('archived_webhook_payloads')->insert([
            'event_id' => 'uuid-to-restore',
            'order_id' => $this->order->id,
            'payload' => json_encode(['restored' => 'data']),
            'archived_at' => now(),
        ]);

        $this->artisan('webhooks:restore-payload', ['eventId' => 'uuid-to-restore'])->assertSuccessful();

        $fresh = $event->fresh();
        $this->assertSame(['restored' => 'data'], $fresh->payload);
        $this->assertSame('blocked', $fresh->status); // Status remains blocked!
        $this->assertSame('configuration_missing', $fresh->last_error);
    }

    public function test_rollback_payloads_restores_all_in_chunks_idempotently(): void
    {
        $event = WebhookOutbox::create([
            'event_id' => 'uuid-rollback-1',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => null,
            'status' => 'delivered',
            'delivered_at' => now()->subDays(35),
        ]);

        DB::table('archived_webhook_payloads')->insert([
            'event_id' => 'uuid-rollback-1',
            'order_id' => $this->order->id,
            'payload' => json_encode(['rollback' => true]),
            'archived_at' => now(),
        ]);

        $restored = $this->service->rollbackPayloads(50);
        $this->assertSame(1, $restored);
        $this->assertSame(['rollback' => true], $event->fresh()->payload);

        // Second run is idempotent (0 affected)
        $secondRun = $this->service->rollbackPayloads(50);
        $this->assertSame(0, $secondRun);
    }
}
