<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use App\Services\WebhookRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookLegacyLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private WebhookRetentionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::create(['name' => 'Retention Test', 'email' => 'retention@example.com', 'password' => 'password12345']);
        $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'Retention Test', 'payee_name' => 'Test', 'upi_id' => 'test@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);
        $this->service = app(WebhookRetentionService::class);
    }

    public function test_live_failed_pending_and_review_orders_fail_closed(): void
    {
        $pendingOrder = app(OrderService::class)->create($this->merchant, ['amount' => 100]);

        $reviewOrder = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
        $reviewOrder->update(['status' => Order::REVIEW]);

        $failedOrder = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
        $failedOrder->update(['status' => Order::FAILED]); // Live failed order

        $this->assertFalse($this->service->isOrderEligibleForLogArchival($pendingOrder, 30, 86700));
        $this->assertFalse($this->service->isOrderEligibleForLogArchival($reviewOrder, 30, 86700));
        $this->assertFalse($this->service->isOrderEligibleForLogArchival($failedOrder, 30, 86700));
    }

    public function test_expired_order_respects_full_late_payment_grace_and_skew(): void
    {
        $order = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
        $order->forceFill([
            'status' => Order::EXPIRED,
            'expires_at' => now()->subDays(30),
            'original_expires_at' => now()->subDays(30),
        ])->save();

        // With 30 retention days + 86,700s late recovery buffer, an order expired 30d ago is still in the buffer!
        $this->assertFalse($this->service->isOrderEligibleForLogArchival($order, 30, 86700));

        // When expired 32 days ago, it is safely past the buffer
        $order->forceFill([
            'expires_at' => now()->subDays(32),
            'original_expires_at' => now()->subDays(32),
        ])->save();
        $this->assertTrue($this->service->isOrderEligibleForLogArchival($order, 30, 86700));
    }

    public function test_sandbox_failed_order_uses_sandbox_simulated_at(): void
    {
        $order = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
        $order->forceFill([
            'status' => Order::FAILED,
            'environment' => Order::SANDBOX,
            'sandbox_simulated_at' => now()->subDays(35),
        ])->save();

        $this->assertTrue($this->service->isOrderEligibleForLogArchival($order, 30, 86700));

        $order->forceFill(['sandbox_simulated_at' => now()->subDays(5)])->save();
        $this->assertFalse($this->service->isOrderEligibleForLogArchival($order, 30, 86700));
    }

    public function test_order_with_any_undelivered_outbox_event_fails_closed(): void
    {
        $paidOrder = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
        $paidOrder->forceFill(['status' => Order::PAID, 'paid_at' => now()->subDays(35)])->save();

        WebhookOutbox::create([
            'event_id' => 'uuid-undelivered',
            'order_id' => $paidOrder->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$paidOrder->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => [],
            'status' => 'queued', // Still queued!
            'delivered_at' => null,
        ]);

        $this->assertFalse($this->service->isOrderEligibleForLogArchival($paidOrder, 30, 86700));

        // When marked delivered:
        WebhookOutbox::where('event_id', 'uuid-undelivered')->update([
            'status' => 'delivered',
            'delivered_at' => now()->subDays(35),
        ]);

        $this->assertTrue($this->service->isOrderEligibleForLogArchival($paidOrder, 30, 86700));
    }
}
