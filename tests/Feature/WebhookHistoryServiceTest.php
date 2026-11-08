<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\WebhookHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebhookHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Order $order;

    private WebhookHistoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::create(['name' => 'History Test', 'email' => 'history@example.com', 'password' => 'password12345']);
        $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'History Test', 'payee_name' => 'Test', 'upi_id' => 'test@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);
        $this->order = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
        $this->service = app(WebhookHistoryService::class);
    }

    public function test_combines_active_and_archived_logs_with_deterministic_sort(): void
    {
        $t1 = now()->subMinutes(10);
        $t2 = now()->subMinutes(5);

        // Active log
        DB::table('webhook_logs')->insert([
            'id' => 10,
            'order_id' => $this->order->id,
            'event' => 'order.paid',
            'event_id' => 'uuid-10',
            'url' => 'https://merchant.example/hook',
            'payload' => json_encode(['foo' => 'bar']),
            'response_code' => 200,
            'success' => true,
            'attempt' => 1,
            'created_at' => $t2,
            'updated_at' => $t2,
        ]);

        // Archived log with earlier timestamp
        DB::table('archived_webhook_logs')->insert([
            'id' => 5,
            'order_id' => $this->order->id,
            'event' => 'order.paid',
            'event_id' => 'uuid-5',
            'url' => 'https://merchant.example/hook',
            'payload' => json_encode(['foo' => 'baz']),
            'response_code' => 500,
            'success' => false,
            'attempt' => 1,
            'created_at' => $t1,
            'updated_at' => $t1,
        ]);

        $logs = $this->service->getAllLogsForOrder($this->order);

        $this->assertCount(2, $logs);
        $this->assertSame(10, (int) $logs[0]->id);
        $this->assertSame('active', $logs[0]->storage_tier);
        $this->assertSame(5, (int) $logs[1]->id);
        $this->assertSame('archive', $logs[1]->storage_tier);
    }

    public function test_deterministic_tie_breaking_when_timestamps_and_ids_are_identical(): void
    {
        $time = now()->subDays(1);

        // Active log with id 42
        DB::table('webhook_logs')->insert([
            'id' => 42,
            'order_id' => $this->order->id,
            'event' => 'order.paid',
            'event_id' => 'uuid-active-42',
            'url' => 'https://merchant.example/hook',
            'payload' => json_encode(['tier' => 'active']),
            'response_code' => 200,
            'success' => true,
            'attempt' => 2,
            'created_at' => $time,
            'updated_at' => $time,
        ]);

        // Archived log with same numeric id 42 and same timestamp
        DB::table('archived_webhook_logs')->insert([
            'id' => 42,
            'order_id' => $this->order->id,
            'event' => 'order.paid',
            'event_id' => 'uuid-archive-42',
            'url' => 'https://merchant.example/hook',
            'payload' => json_encode(['tier' => 'archive']),
            'response_code' => 500,
            'success' => false,
            'attempt' => 1,
            'created_at' => $time,
            'updated_at' => $time,
        ]);

        $logs = $this->service->getAllLogsForOrder($this->order);

        $this->assertCount(2, $logs);
        // 'active' comes before 'archive' under storage_tier ASC
        $this->assertSame('active', $logs[0]->storage_tier);
        $this->assertSame('archive', $logs[1]->storage_tier);
    }

    public function test_cross_tier_reconciliation_detects_archived_success(): void
    {
        DB::table('archived_webhook_logs')->insert([
            'id' => 99,
            'order_id' => $this->order->id,
            'event' => 'order.paid',
            'event_id' => 'uuid-reconcile-99',
            'url' => 'https://merchant.example/hook',
            'payload' => json_encode([]),
            'response_code' => 200,
            'success' => true,
            'attempt' => 1,
            'created_at' => now()->subDays(35),
            'updated_at' => now()->subDays(35),
        ]);

        $this->assertTrue($this->service->hasSuccessfulLog($this->order->id, 'uuid-reconcile-99'));
        $this->assertFalse($this->service->hasSuccessfulLog($this->order->id, 'non-existent-uuid'));
    }
}
