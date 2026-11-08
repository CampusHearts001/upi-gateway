<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\WebhookRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebhookArchiveConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Order $order;

    private WebhookRetentionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::create(['name' => 'Conflict Test', 'email' => 'conflict@example.com', 'password' => 'password12345']);
        $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'Conflict Test', 'payee_name' => 'Test', 'upi_id' => 'test@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);
        $this->order = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
        $this->order->forceFill(['status' => Order::PAID, 'paid_at' => now()->subDays(35)])->save();
        $this->service = app(WebhookRetentionService::class);
    }

    public function test_exact_13_column_match_allows_active_deletion_without_overwriting(): void
    {
        $time = now()->subDays(35);

        // Active log
        DB::table('webhook_logs')->insert([
            'id' => 77,
            'order_id' => $this->order->id,
            'event' => 'order.paid',
            'event_id' => 'uuid-77',
            'url' => 'https://merchant.example/hook',
            'payload' => json_encode(['foo' => 'bar']),
            'response_code' => 200,
            'response_body' => null,
            'success' => true,
            'attempt' => 1,
            'error_label' => null,
            'created_at' => $time,
            'updated_at' => $time,
        ]);

        // Existing identical archive record
        DB::table('archived_webhook_logs')->insert([
            'id' => 77,
            'order_id' => $this->order->id,
            'event' => 'order.paid',
            'event_id' => 'uuid-77',
            'url' => 'https://merchant.example/hook',
            'payload' => json_encode(['foo' => 'bar']),
            'response_code' => 200,
            'response_body' => null,
            'success' => true,
            'attempt' => 1,
            'error_label' => null,
            'created_at' => $time,
            'updated_at' => $time,
        ]);

        $archivedCount = $this->service->pruneEligibleLogs(10);

        // Active row was deleted safely, archive remains intact
        $this->assertSame(1, $archivedCount);
        $this->assertDatabaseMissing('webhook_logs', ['id' => 77]);
        $this->assertDatabaseHas('archived_webhook_logs', ['id' => 77]);
    }

    public function test_column_mismatch_aborts_active_deletion_and_preserves_archive(): void
    {
        $time = now()->subDays(35);

        // Active log with response_code 500
        DB::table('webhook_logs')->insert([
            'id' => 88,
            'order_id' => $this->order->id,
            'event' => 'order.paid',
            'event_id' => 'uuid-88',
            'url' => 'https://merchant.example/hook',
            'payload' => json_encode(['version' => 'active']),
            'response_code' => 500,
            'response_body' => null,
            'success' => false,
            'attempt' => 1,
            'error_label' => 'http_500',
            'created_at' => $time,
            'updated_at' => $time,
        ]);

        // Conflicting archive record with response_code 200 and different payload
        DB::table('archived_webhook_logs')->insert([
            'id' => 88,
            'order_id' => $this->order->id,
            'event' => 'order.paid',
            'event_id' => 'uuid-88',
            'url' => 'https://merchant.example/hook',
            'payload' => json_encode(['version' => 'archive']),
            'response_code' => 200,
            'response_body' => null,
            'success' => true,
            'attempt' => 1,
            'error_label' => null,
            'created_at' => $time,
            'updated_at' => $time,
        ]);

        $archivedCount = $this->service->pruneEligibleLogs(10);

        // Active row must NOT be deleted due to mismatch!
        $this->assertSame(0, $archivedCount);
        $this->assertDatabaseHas('webhook_logs', ['id' => 88]);
        $this->assertDatabaseHas('archived_webhook_logs', ['id' => 88, 'response_code' => 200]);
    }
}
