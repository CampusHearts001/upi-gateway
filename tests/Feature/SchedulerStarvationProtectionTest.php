<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SchedulerStarvationProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private PaymentAccount $account;

    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();

        $this->orderService = app(OrderService::class);
        $this->merchant = User::create([
            'name' => 'Scheduler Starvation Merchant',
            'email' => 'scheduler@example.com',
            'password' => 'password12345',
        ]);
        $this->merchant->regenerateApiCredentials();

        $this->account = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_pg',
            'label' => 'Scheduler PG Account',
            'payee_name' => 'Scheduler Payee',
            'upi_id' => 'sched@upi',
            'credentials' => [
                'environment' => 'production',
                'mid' => 'TESTMID',
                'merchant_key' => 'abcd1234EFGH5678',
                'client_id' => 'TESTCLIENT',
                'client_secret' => 'TESTSECRET',
                'client_version' => '1',
            ],
        ]);
    }

    private function createPendingOrder(array $overrides = []): Order
    {
        $order = $this->orderService->create($this->merchant, [
            'amount' => '100.00',
            'payment_account_id' => $this->account->id,
            'client_order_id' => 'PEND-'.uniqid(),
        ]);

        if (! empty($overrides)) {
            $order->forceFill($overrides)->save();
        }

        return $order->fresh()->load('paymentAccount');
    }

    private function createExpiredOrder(array $overrides = []): Order
    {
        $order = $this->orderService->create($this->merchant, [
            'amount' => '100.00',
            'payment_account_id' => $this->account->id,
            'client_order_id' => 'EXP-'.uniqid(),
        ]);

        $createdAt = now()->subHours(2);
        $expiresAt = now()->subHour();

        $order->forceFill(array_merge([
            'status' => Order::EXPIRED,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'original_expires_at' => $expiresAt,
            'last_checked_at' => null,
        ], $overrides))->save();

        return $order->fresh()->load('paymentAccount');
    }

    private function mockPaytmPg(array $responses = []): void
    {
        Http::fake([
            'secure.paytmpayments.com/v3/order/status' => function (Request $request) use ($responses) {
                $orderId = $request['body']['orderId'] ?? null;
                if ($orderId && isset($responses[$orderId])) {
                    return Http::response(['body' => $responses[$orderId]]);
                }

                // Default pending response
                return Http::response([
                    'body' => [
                        'resultInfo' => ['resultStatus' => 'PENDING'],
                        'orderId' => $orderId,
                        'txnAmount' => '100.00',
                    ],
                ]);
            },
        ]);
    }

    public function test_pending_backlog_exceeding_batch_size_does_not_starve_late_payment_recovery(): void
    {
        config([
            'gateway.pending_verification_batch_size' => 2,
            'gateway.late_payment_batch_size' => 2,
        ]);

        // Create 5 pending orders (more than pending batch size of 2)
        $pending = [];
        for ($i = 0; $i < 5; $i++) {
            $pending[] = $this->createPendingOrder();
        }

        // Create 1 expired order with confirmed late payment
        $expired = $this->createExpiredOrder();

        // Payment completed 75 minutes ago (between created_at 120m ago and original_expires_at 60m ago)
        $this->mockPaytmPg([
            $expired->order_no => [
                'resultInfo' => ['resultStatus' => 'TXN_SUCCESS'],
                'orderId' => $expired->order_no,
                'txnAmount' => '100.00',
                'currency' => 'INR',
                'bankTxnId' => '123456789012',
                'txnId' => 'LATE-TXN-1',
                'txnDate' => now()->subMinutes(75)->format('Y-m-d H:i:s'),
            ],
        ]);

        $this->artisan('orders:check-pending')
            ->expectsOutput('Checked 2 pending orders.')
            ->expectsOutput('Processed 1 expired orders for late-payment recovery.')
            ->expectsOutput('Successfully recovered 1 late payment(s).')
            ->assertSuccessful();

        // Assert pending batch limit was strictly enforced: exactly 2 pending orders checked
        $checkedPendingCount = Order::where('status', Order::PENDING)->whereNotNull('last_checked_at')->count();
        $this->assertSame(2, $checkedPendingCount);

        // Assert the remaining 3 pending orders were NOT checked yet (still null last_checked_at)
        $uncheckedPendingCount = Order::where('status', Order::PENDING)->whereNull('last_checked_at')->count();
        $this->assertSame(3, $uncheckedPendingCount);

        // Assert late payment recovery was NOT starved: the expired order was checked and recovered to PAID
        $this->assertSame(Order::PAID, $expired->fresh()->status);
        $this->assertNotNull($expired->fresh()->paid_at);
        $this->assertSame('123456789012', $expired->fresh()->utr);

        // Assert transactional webhook outbox event was recorded for the recovered order
        $outboxEvent = WebhookOutbox::where('order_id', $expired->id)->where('event', 'order.paid')->first();
        $this->assertNotNull($outboxEvent);
        $this->assertSame('pending', $outboxEvent->status);
    }

    public function test_bounded_batch_limits_are_strictly_enforced_for_both_phases(): void
    {
        config([
            'gateway.pending_verification_batch_size' => 3,
            'gateway.late_payment_batch_size' => 2,
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->createPendingOrder();
        }

        for ($i = 0; $i < 5; $i++) {
            $this->createExpiredOrder();
        }

        $this->mockPaytmPg();

        $this->artisan('orders:check-pending')
            ->expectsOutput('Checked 3 pending orders.')
            ->expectsOutput('Processed 2 expired orders for late-payment recovery.')
            ->assertSuccessful();

        $this->assertSame(3, Order::where('status', Order::PENDING)->whereNotNull('last_checked_at')->count());
        $this->assertSame(3, Order::where('status', Order::PENDING)->whereNull('last_checked_at')->count());

        $this->assertSame(2, Order::where('status', Order::EXPIRED)->whereNotNull('last_checked_at')->count());
        $this->assertSame(3, Order::where('status', Order::EXPIRED)->whereNull('last_checked_at')->count());
    }

    public function test_validation_exception_in_pending_phase_does_not_abort_late_payment_recovery(): void
    {
        config([
            'gateway.pending_verification_batch_size' => 5,
            'gateway.late_payment_batch_size' => 5,
        ]);

        // Pending order with misconfigured account environment
        $invalidAccount = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_pg',
            'label' => 'Mismatched Sandbox Account',
            'payee_name' => 'Sandbox Payee',
            'upi_id' => 'sandbox@upi',
            'credentials' => ['environment' => 'sandbox', 'mid' => 'SANDBOXMID'],
        ]);
        $invalidPending = $this->createPendingOrder();
        $invalidPending->forceFill(['payment_account_id' => $invalidAccount->id])->save();

        // Expired order with valid payment
        $expired = $this->createExpiredOrder();

        $this->mockPaytmPg([
            $expired->order_no => [
                'resultInfo' => ['resultStatus' => 'TXN_SUCCESS'],
                'orderId' => $expired->order_no,
                'txnAmount' => '100.00',
                'currency' => 'INR',
                'bankTxnId' => '123456789012',
                'txnId' => 'LATE-TXN-VAL',
                'txnDate' => now()->subMinutes(75)->format('Y-m-d H:i:s'),
            ],
        ]);

        $this->artisan('orders:check-pending')
            ->expectsOutput('Checked 0 pending orders.')
            ->expectsOutput('Rejected 1 pending orders; review their payment account configuration.')
            ->expectsOutput('Processed 1 expired orders for late-payment recovery.')
            ->expectsOutput('Successfully recovered 1 late payment(s).')
            ->assertSuccessful();

        // Expired order was still recovered despite pending validation failure
        $this->assertSame(Order::PAID, $expired->fresh()->status);
        // Rejected pending order had its last_checked_at set so it yields to other orders
        $this->assertNotNull($invalidPending->fresh()->last_checked_at);
    }

    public function test_unexpected_database_failure_in_pending_phase_does_not_abort_late_payment_phase(): void
    {
        config([
            'gateway.pending_verification_batch_size' => 2,
            'gateway.late_payment_batch_size' => 2,
        ]);

        $expired = $this->createExpiredOrder();

        $this->mockPaytmPg([
            $expired->order_no => [
                'resultInfo' => ['resultStatus' => 'TXN_SUCCESS'],
                'orderId' => $expired->order_no,
                'txnAmount' => '100.00',
                'currency' => 'INR',
                'bankTxnId' => '123456789012',
                'txnId' => 'LATE-TXN-DBERR',
                'txnDate' => now()->subMinutes(75)->format('Y-m-d H:i:s'),
            ],
        ]);

        // Mock OrderService to throw an unexpected exception on pending orders, but work on expired orders
        $mockOrders = $this->createMock(OrderService::class);
        $mockOrders->method('check')->willReturnCallback(function (Order $order) {
            if ($order->status === Order::PENDING) {
                throw new \RuntimeException('Simulated catastrophic database failure during pending check');
            }

            return $this->orderService->check($order);
        });
        $mockOrders->method('verificationLogContext')->willReturnCallback(fn ($order) => $this->orderService->verificationLogContext($order));
        $this->app->instance(OrderService::class, $mockOrders);

        $this->createPendingOrder();

        $this->artisan('orders:check-pending')
            ->expectsOutput('Checked 0 pending orders.')
            ->expectsOutput('Processed 1 expired orders for late-payment recovery.')
            ->expectsOutput('Successfully recovered 1 late payment(s).')
            ->assertSuccessful();

        $this->assertSame(Order::PAID, $expired->fresh()->status);
    }

    public function test_fair_deterministic_selection_rotates_orders_across_cycles(): void
    {
        config(['gateway.pending_verification_batch_size' => 2]);

        $p1 = $this->createPendingOrder();
        $p2 = $this->createPendingOrder();
        $p3 = $this->createPendingOrder();
        $p4 = $this->createPendingOrder();

        $this->mockPaytmPg();

        // Cycle 1: p1 and p2 are selected (NULL last_checked_at, lowest IDs)
        $this->artisan('orders:check-pending')
            ->expectsOutput('Checked 2 pending orders.')
            ->assertSuccessful();

        $p1Fresh = $p1->fresh();
        $p2Fresh = $p2->fresh();
        $this->assertNotNull($p1Fresh->last_checked_at);
        $this->assertNotNull($p2Fresh->last_checked_at);
        $this->assertNull($p3->fresh()->last_checked_at);
        $this->assertNull($p4->fresh()->last_checked_at);

        // Cycle 2: p3 and p4 are selected because their last_checked_at is still NULL
        $this->artisan('orders:check-pending')
            ->expectsOutput('Checked 2 pending orders.')
            ->assertSuccessful();

        $this->assertNotNull($p3->fresh()->last_checked_at);
        $this->assertNotNull($p4->fresh()->last_checked_at);

        // All 4 orders received their turn
        $this->assertSame(4, Order::where('status', Order::PENDING)->whereNotNull('last_checked_at')->count());
    }

    public function test_fair_deterministic_selection_rotates_expired_orders_across_cycles(): void
    {
        config(['gateway.late_payment_batch_size' => 2]);

        $e1 = $this->createExpiredOrder();
        $e2 = $this->createExpiredOrder();
        $e3 = $this->createExpiredOrder();
        $e4 = $this->createExpiredOrder();

        $this->mockPaytmPg();

        // Cycle 1: e1 and e2 are processed
        $this->artisan('orders:check-pending')
            ->expectsOutput('Processed 2 expired orders for late-payment recovery.')
            ->assertSuccessful();

        $this->assertNotNull($e1->fresh()->last_checked_at);
        $this->assertNotNull($e2->fresh()->last_checked_at);
        $this->assertNull($e3->fresh()->last_checked_at);
        $this->assertNull($e4->fresh()->last_checked_at);

        // Cycle 2: e3 and e4 are processed
        $this->artisan('orders:check-pending')
            ->expectsOutput('Processed 2 expired orders for late-payment recovery.')
            ->assertSuccessful();

        $this->assertNotNull($e3->fresh()->last_checked_at);
        $this->assertNotNull($e4->fresh()->last_checked_at);
    }

    public function test_distributed_scheduler_lock_prevents_concurrent_overlapping_executions(): void
    {
        $this->mockPaytmPg();
        $pending = $this->createPendingOrder();

        // Simulate an ongoing scheduler cycle holding the lock
        $lockKey = 'scheduler:orders:check-pending';
        $lock = Cache::lock($lockKey, 300);
        $this->assertTrue($lock->get());

        // A second execution must skip gracefully
        $this->artisan('orders:check-pending')
            ->expectsOutput('Another order verification process is currently running; skipping this cycle.')
            ->assertSuccessful();

        // The order was not touched
        $this->assertNull($pending->fresh()->last_checked_at);

        // Release lock
        $lock->release();

        // Now the command can execute normally
        $this->artisan('orders:check-pending')
            ->expectsOutput('Checked 1 pending orders.')
            ->assertSuccessful();

        $this->assertNotNull($pending->fresh()->last_checked_at);
    }

    public function test_distributed_lock_is_released_cleanly_on_completion(): void
    {
        $this->mockPaytmPg();
        $this->createPendingOrder();

        $this->artisan('orders:check-pending')->assertSuccessful();

        // After completion, the distributed lock must be freely acquirable immediately
        $lockKey = 'scheduler:orders:check-pending';
        $lock = Cache::lock($lockKey, 10);
        $this->assertTrue($lock->get(), 'Distributed scheduler lock was not released upon command completion');
        $lock->release();
    }

    public function test_expired_distributed_lock_recovers_automatically_after_timeout(): void
    {
        $this->mockPaytmPg();
        $this->createPendingOrder();

        // Acquire lock with short lease simulating a crashed process
        $lockKey = 'scheduler:orders:check-pending';
        $crashedLock = Cache::lock($lockKey, 5);
        $this->assertTrue($crashedLock->get());

        // Advance beyond the lock duration
        $this->travel(6)->seconds();

        // Command must acquire the expired lock and proceed successfully
        $this->artisan('orders:check-pending')
            ->expectsOutput('Checked 1 pending orders.')
            ->assertSuccessful();
    }

    public function test_structured_logging_records_full_cycle_metrics(): void
    {
        Log::spy();
        $this->mockPaytmPg();

        $pending = $this->createPendingOrder();
        $expired = $this->createExpiredOrder();

        $this->artisan('orders:check-pending')->assertSuccessful();

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            if ($message !== 'Order verification scheduler cycle completed') {
                return false;
            }

            return isset($context['duration_ms'])
                && isset($context['pending']['eligible'])
                && isset($context['pending']['selected'])
                && isset($context['pending']['checked'])
                && isset($context['pending']['paid'])
                && isset($context['pending']['expired'])
                && isset($context['pending']['skipped'])
                && isset($context['pending']['rejected'])
                && isset($context['pending']['failed'])
                && isset($context['pending']['duration_ms'])
                && isset($context['late_payment']['eligible'])
                && isset($context['late_payment']['selected'])
                && isset($context['late_payment']['checked'])
                && isset($context['late_payment']['recovered_paid'])
                && isset($context['late_payment']['skipped'])
                && isset($context['late_payment']['rejected'])
                && isset($context['late_payment']['failed'])
                && isset($context['late_payment']['duration_ms']);
        })->once();
    }

    public function test_skipped_metric_increments_when_order_verification_lease_is_held_by_another_worker(): void
    {
        $this->mockPaytmPg();
        $order = $this->createPendingOrder();

        // Hold order-level verification cache lock
        $orderLock = Cache::lock('order-verification:'.$order->id, 120);
        $this->assertTrue($orderLock->get());

        Log::spy();

        $this->artisan('orders:check-pending')->assertSuccessful();

        $orderLock->release();

        // The order was skipped because its verification lock was held
        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            return $message === 'Order verification scheduler cycle completed'
                && $context['pending']['skipped'] === 1;
        })->once();
    }

    public function test_late_payment_skipped_metric_increments_when_lease_is_held_and_zero_pending_orders(): void
    {
        $this->mockPaytmPg();
        // Zero pending orders in database
        $expired = $this->createExpiredOrder();

        // Hold order-level verification cache lock simulating another worker actively verifying it
        $orderLock = Cache::lock('order-verification:'.$expired->id, 120);
        $this->assertTrue($orderLock->get());

        Log::spy();

        $this->artisan('orders:check-pending')->assertSuccessful();

        $orderLock->release();

        // The expired order was skipped because its verification lock was held
        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            return $message === 'Order verification scheduler cycle completed'
                && $context['late_payment']['skipped'] === 1
                && $context['pending']['selected'] === 0;
        })->once();

        // Ensure order remains expired and last_checked_at was not touched
        $this->assertSame(Order::EXPIRED, $expired->fresh()->status);
        $this->assertNull($expired->fresh()->last_checked_at);
    }

    public function test_priority_26_evidence_binding_is_enforced_during_late_payment_recovery(): void
    {
        // Expired order created 2 hours ago, expired 1 hour ago
        $expiredStale = $this->createExpiredOrder();

        // Payment timestamp claims to be 3 hours ago (before order was created)
        $this->mockPaytmPg([
            $expiredStale->order_no => [
                'resultInfo' => ['resultStatus' => 'TXN_SUCCESS'],
                'orderId' => $expiredStale->order_no,
                'txnAmount' => '100.00',
                'currency' => 'INR',
                'bankTxnId' => '123456789012',
                'txnId' => 'STALE-TXN',
                'txnDate' => now()->subHours(3)->format('Y-m-d H:i:s'),
            ],
        ]);

        $this->artisan('orders:check-pending')
            ->expectsOutput('Processed 0 expired orders for late-payment recovery.')
            ->assertSuccessful();

        // Invalid payment completed before order creation must NOT be marked paid
        $this->assertSame(Order::EXPIRED, $expiredStale->fresh()->status);
        $this->assertNull($expiredStale->fresh()->paid_at);
        $this->assertSame(0, WebhookOutbox::where('order_id', $expiredStale->id)->count());
    }

    public function test_exactly_one_paid_transition_and_one_outbox_event_under_scheduler(): void
    {
        $expired = $this->createExpiredOrder();

        $this->mockPaytmPg([
            $expired->order_no => [
                'resultInfo' => ['resultStatus' => 'TXN_SUCCESS'],
                'orderId' => $expired->order_no,
                'txnAmount' => '100.00',
                'currency' => 'INR',
                'bankTxnId' => '123456789012',
                'txnId' => 'LATE-TXN-EXACT',
                'txnDate' => now()->subMinutes(75)->format('Y-m-d H:i:s'),
            ],
        ]);

        // First run recovers payment
        $this->artisan('orders:check-pending')->assertSuccessful();
        $this->assertSame(Order::PAID, $expired->fresh()->status);

        // Second run should not duplicate paid transition or outbox event
        $this->artisan('orders:check-pending')->assertSuccessful();

        $this->assertSame(1, WebhookOutbox::where('order_id', $expired->id)->where('event', 'order.paid')->count());
    }
}
