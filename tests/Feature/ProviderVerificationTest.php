<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\VerificationLeaseRecoveryAudit;
use App\Services\OrderService;
use App\Services\VerificationLease;
use App\Services\VerificationNodeRegistry;
use App\Services\VerificationProcessInspector;
use App\Services\Verifiers\VerificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProviderVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderService = app(OrderService::class);
        $merchant = User::create([
            'name' => 'Verify Test Merchant',
            'email' => 'verify-test@example.com',
            'password' => 'password12345',
        ]);
        $merchant->regenerateApiCredentials();
        $merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Test Merchant Account',
            'payee_name' => 'Test Payee',
            'upi_id' => 'test@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);
        $this->order = $this->orderService->create($merchant, ['amount' => 100])->load('paymentAccount');
    }

    public function test_verification_lease_is_acquired_with_unique_token_and_incrementing_generation(): void
    {
        $lease = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease);
        $this->assertSame(1, $lease->generation);
        $this->assertNotEmpty($lease->token);

        $fresh = $this->order->fresh();
        $this->assertSame($lease->token, $fresh->verification_token);
        $this->assertSame(1, $fresh->verification_generation);
        $this->assertNotNull($fresh->verification_expires_at);
        $this->assertTrue($fresh->verification_expires_at->isFuture());
        $this->assertSame(getmypid(), (int) $fresh->verification_pid);
        $this->assertSame($lease->nodeId, $fresh->verification_node_id);
        $this->assertSame($lease->nodeInstanceId, $fresh->verification_node_instance_id);
        $this->assertSame($lease->processIdentity, $fresh->verification_process_identity);

        // A second acquisition while lease is active must be rejected
        $secondLease = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNull($secondLease);
    }

    public function test_verification_lease_releases_cleanly_and_subsequent_generation_increments(): void
    {
        $lease1 = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease1);
        $this->assertSame(1, $lease1->generation);

        $this->orderService->releaseVerificationLease($this->order, $lease1);

        $fresh = $this->order->fresh();
        $this->assertNull($fresh->verification_token);
        $this->assertNull($fresh->verification_expires_at);
        $this->assertNull($fresh->verification_pid);
        $this->assertNull($fresh->verification_node_id);
        $this->assertNull($fresh->verification_node_instance_id);
        $this->assertNull($fresh->verification_process_identity);
        $this->assertSame(1, $fresh->verification_generation); // Preserved

        $lease2 = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease2);
        $this->assertSame(2, $lease2->generation);
        $this->assertNotSame($lease1->token, $lease2->token);
    }

    public function test_stale_lease_cannot_clear_newer_owner_lease(): void
    {
        $lease1 = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease1);

        $this->orderService->releaseVerificationLease($this->order, $lease1);
        $lease2 = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease2);
        $this->assertSame(2, $lease2->generation);

        // Stale owner 1 attempts release
        $this->orderService->releaseVerificationLease($this->order, $lease1);

        $fresh = $this->order->fresh();
        $this->assertSame($lease2->token, $fresh->verification_token);
        $this->assertSame(2, $fresh->verification_generation);
        $this->assertNotNull($fresh->verification_expires_at);
    }

    public function test_stale_generation_cannot_commit_mark_paid(): void
    {
        $lease1 = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease1);

        $this->orderService->releaseVerificationLease($this->order, $lease1);
        $lease2 = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease2);
        $this->assertSame(2, $lease2->generation);

        // Stale owner 1 tries to mark order as paid
        $result = VerificationResult::fromPaytmBusiness($this->order, 100, '123456789012', 'TXN-STALE');
        $this->orderService->markPaid($this->order, $result, $lease1);

        $fresh = $this->order->fresh();
        $this->assertSame(Order::PENDING, $fresh->status);
        $this->assertNull($fresh->paid_at);
        $this->assertDatabaseCount('webhook_outbox', 0);
    }

    public function test_stale_generation_cannot_commit_expire(): void
    {
        $lease1 = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease1);

        $this->orderService->releaseVerificationLease($this->order, $lease1);
        $lease2 = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease2);

        $this->orderService->expire($this->order, $lease1);

        $fresh = $this->order->fresh();
        $this->assertSame(Order::PENDING, $fresh->status);
        $this->assertDatabaseCount('webhook_outbox', 0);
    }

    public function test_terminal_paid_order_rejects_verification_lease_acquisition(): void
    {
        $result = VerificationResult::fromPaytmBusiness($this->order, 100, '123456789012', 'TXN-PAID');
        $lease = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease);
        $this->orderService->markPaid($this->order, $result, $lease);
        $this->assertSame(Order::PAID, $this->order->fresh()->status);

        $this->assertNull($this->orderService->acquireVerificationLease($this->order));
    }

    public function test_elapsed_hard_timeout_never_replaces_a_still_active_execution(): void
    {
        $lease = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease);
        $this->order->forceFill([
            'verification_started_at' => now()->subSeconds(305),
            'verification_expires_at' => now()->subSeconds(185),
        ])->save();

        $this->assertNull($this->orderService->acquireVerificationLease($this->order));
        $this->assertSame($lease->token, $this->order->fresh()->verification_token);
        $this->assertSame(1, $this->order->fresh()->verification_generation);
    }

    public function test_confirmed_dead_process_is_recoverable_after_lease_expiry(): void
    {
        $inspector = app(VerificationProcessInspector::class);
        $this->order->forceFill([
            'verification_token' => 'stale-dead-token',
            'verification_generation' => 5,
            'verification_started_at' => now()->subSeconds(305),
            'verification_expires_at' => now()->subSeconds(185),
            'verification_pid' => 99999999,
            'verification_node_id' => $inspector->nodeId(),
            'verification_node_instance_id' => $inspector->nodeInstanceId(),
            'verification_process_identity' => str_repeat('a', 64),
        ])->save();

        $recoveredLease = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($recoveredLease);
        $this->assertSame(6, $recoveredLease->generation);
        $this->assertNotSame('stale-dead-token', $recoveredLease->token);
    }

    public function test_pid_reuse_is_detected_by_process_start_identity(): void
    {
        $inspector = app(VerificationProcessInspector::class);
        $this->order->forceFill([
            'verification_token' => 'old-execution',
            'verification_generation' => 1,
            'verification_expires_at' => now()->subSecond(),
            'verification_pid' => getmypid(),
            'verification_node_id' => $inspector->nodeId(),
            'verification_node_instance_id' => $inspector->nodeInstanceId(),
            'verification_process_identity' => str_repeat('f', 64),
        ])->save();

        $lease = $this->orderService->acquireVerificationLease($this->order);
        $this->assertNotNull($lease);
        $this->assertSame(2, $lease->generation);
    }

    public function test_unknown_or_permission_denied_liveness_fails_closed(): void
    {
        $inspector = \Mockery::mock(VerificationProcessInspector::class);
        $inspector->shouldReceive('status')->once()->andReturn(VerificationProcessInspector::UNKNOWN);
        $service = new OrderService($inspector, app(VerificationNodeRegistry::class));
        $this->order->forceFill([
            'verification_token' => 'unknown-owner',
            'verification_generation' => 1,
            'verification_expires_at' => now()->subSecond(),
            'verification_pid' => 1,
            'verification_node_id' => 'another-node',
            'verification_node_instance_id' => str_repeat('c', 64),
            'verification_process_identity' => str_repeat('b', 64),
        ])->save();

        $this->assertNull($service->acquireVerificationLease($this->order));
        $this->assertSame('unknown-owner', $this->order->fresh()->verification_token);
    }

    public function test_provider_mark_paid_requires_a_current_lease(): void
    {
        $result = VerificationResult::fromPaytmBusiness($this->order, 100, '123456789012', 'TXN-NO-LEASE');

        try {
            $this->orderService->markPaid($this->order, $result);
            $this->fail('A provider transition without a lease must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order', $e->errors());
        }
        $this->assertSame(Order::PENDING, $this->order->fresh()->status);
        $this->assertDatabaseCount('webhook_outbox', 0);
    }

    public function test_stale_null_expiry_repair_cannot_modify_a_newer_paid_generation(): void
    {
        $lease1 = $this->verificationLease($this->order);
        $this->orderService->releaseVerificationLease($this->order, $lease1);
        Order::whereKey($this->order->id)->update(['expires_at' => null]);
        $lease2 = $this->verificationLease($this->order->fresh());
        $result = VerificationResult::fromPaytmBusiness($this->order, 100, '123456789012', 'TXN-NEWER');
        $this->orderService->markPaid($this->order, $result, $lease2);

        $service = new class(app(VerificationProcessInspector::class), app(VerificationNodeRegistry::class)) extends OrderService
        {
            public function attemptRepair(Order $order, VerificationLease $lease): Order
            {
                return $this->repairNullExpiry($order, $lease);
            }
        };
        $service->attemptRepair($this->order, $lease1);

        $fresh = $this->order->fresh();
        $this->assertSame(Order::PAID, $fresh->status);
        $this->assertNull($fresh->expires_at);
        $this->assertSame(2, $fresh->verification_generation);
        $this->assertDatabaseCount('webhook_outbox', 1);
    }

    public function test_unknown_remote_owner_requires_exact_operator_confirmed_recovery(): void
    {
        $lease = $this->verificationLease($this->order);
        $this->order->forceFill([
            'verification_expires_at' => now()->subSecond(),
            'verification_node_id' => 'remote-node',
        ])->save();

        $this->assertNull($this->orderService->acquireVerificationLease($this->order));
        $this->assertSame(1, Artisan::call('orders:recover-verification-lease', [
            'order' => (string) $this->order->id, 'execution' => $lease->token,
            'generation' => (string) $lease->generation, 'operator' => 'test-operator', 'reason' => 'Remote node recovery test',
        ]));
        $this->assertSame($lease->token, $this->order->fresh()->verification_token);
        $this->assertSame(1, Artisan::call('orders:recover-verification-lease', [
            'order' => (string) $this->order->id, 'execution' => 'wrong-token',
            'generation' => (string) $lease->generation, 'operator' => 'test-operator', 'reason' => 'Remote node recovery test',
            '--confirm-stopped' => true, '--confirm-node-shutdown' => true,
        ]));
        $this->assertSame(1, Artisan::call('orders:recover-verification-lease', [
            'order' => (string) $this->order->id, 'execution' => $lease->token,
            'generation' => (string) $lease->generation, 'operator' => 'test-operator', 'reason' => 'Remote node recovery test',
            '--confirm-stopped' => true,
        ]));
        $this->assertSame(0, Artisan::call('orders:recover-verification-lease', [
            'order' => (string) $this->order->id, 'execution' => $lease->token,
            'generation' => (string) $lease->generation, 'operator' => 'test-operator', 'reason' => 'Remote node recovery test',
            '--confirm-stopped' => true, '--confirm-node-shutdown' => true,
        ]));
        $this->assertNull($this->order->fresh()->verification_token);
        $audit = VerificationLeaseRecoveryAudit::firstOrFail();
        $this->assertSame($this->order->id, $audit->order_id);
        $this->assertSame($lease->generation, $audit->previous_generation);
        $this->assertSame('test-operator', $audit->operator_identity);
        $this->assertSame('Remote node recovery test', $audit->reason);
        $this->assertSame(hash('sha256', $lease->token), $audit->execution_token_hash);
        $this->assertSame(2, $this->verificationLease($this->order->fresh())->generation);
    }
}
