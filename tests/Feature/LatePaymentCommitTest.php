<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\WebhookOutboxDispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LatePaymentCommitTest extends TestCase
{
    // Real commits and the real database queue are needed here; Queue::fake()
    // alone does not verify after-commit dispatch or rollback suppression.
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // Remove only isolated test fixtures before DatabaseMigrations invokes
        // the guarded schema rollback; all assertions run before this cleanup.
        try {
            if ($this->app && Schema::hasTable('webhook_outbox')) {
                DB::table('webhook_outbox')->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public static function transactionOutcomes(): array
    {
        return ['commit' => [true], 'rollback' => [false]];
    }

    #[DataProvider('transactionOutcomes')]
    public function test_late_paid_job_follows_database_commit_and_never_duplicates(bool $commit): void
    {
        config(['queue.default' => 'database']);
        Http::preventStrayRequests();
        $merchant = User::create(['name' => 'Commit Test', 'email' => 'commit@example.com', 'password' => 'password12345']);
        $account = $merchant->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'Commit Test', 'payee_name' => 'Commit Test',
            'upi_id' => 'commit@upi', 'credentials' => ['mid' => 'TESTMID'],
        ]);
        $service = app(OrderService::class);
        $order = $service->create($merchant, ['amount' => 100, 'payment_account_id' => $account->id]);
        $order->forceFill([
            'status' => Order::EXPIRED,
            'created_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
            'original_expires_at' => now()->subHour(),
        ])->save();
        Http::fake([config('gateway.paytm_status_url').'*' => Http::response([
            'STATUS' => 'TXN_SUCCESS', 'ORDERID' => $order->order_no, 'TXNAMOUNT' => '100.00',
            'CURRENCY' => 'INR', 'BANKTXNID' => '123456789012', 'TXNID' => 'COMMIT-TXN',
            'TXNDATE' => now()->subMinutes(45)->format('Y-m-d H:i:s'),
        ])]);

        DB::beginTransaction();
        try {
            $service->check($order);
            $this->assertSame(Order::PAID, $order->fresh()->status);
            $this->assertDatabaseCount('jobs', 0);
            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame($commit ? Order::PAID : Order::EXPIRED, $order->fresh()->status);
        $this->assertDatabaseCount('webhook_outbox', $commit ? 1 : 0);
        $this->assertDatabaseCount('jobs', 0);
        // The rolled-back claim is retryable; an already committed payment is a no-op.
        $service->check($order->fresh());
        $service->check($order->fresh(), force: true);
        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertDatabaseCount('webhook_outbox', 1);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertStringContainsString('order.paid', DB::table('jobs')->value('payload'));
        Http::assertSentCount($commit ? 1 : 2);
    }
}
