<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\VerificationLeaseRecoveryAudit;
use App\Models\VerificationNode;
use App\Services\OrderService;
use App\Services\VerificationLease;
use App\Services\VerificationNodeIdentity;
use App\Services\VerificationNodeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class VerificationRecoverySecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::create([
            'name' => 'Recovery Merchant', 'email' => 'recovery@example.test', 'password' => 'password12345',
        ]);
        $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'Recovery', 'payee_name' => 'Recovery',
            'upi_id' => 'recovery@upi', 'credentials' => ['mid' => 'MID'],
        ]);
    }

    public static function invalidProductionNodeIds(): array
    {
        return [[''], ['default'], ['localhost'], ['node-1'], ['upi-gateway-node-1'], ['change-me-unique-node-id']];
    }

    #[DataProvider('invalidProductionNodeIds')]
    public function test_production_rejects_missing_or_placeholder_node_identity(string $nodeId): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.env' => 'production', 'gateway.verification_node_id' => $nodeId]);

        $this->expectException(RuntimeException::class);
        app(VerificationNodeIdentity::class)->nodeId();
    }

    public function test_duplicate_node_registration_fails_closed_but_unique_nodes_register(): void
    {
        config([
            'gateway.verification_node_id' => 'shared-node',
            'gateway.verification_node_installation_id' => 'installation-a',
            'gateway.verification_node_boot_id' => 'boot-a',
        ]);
        app(VerificationNodeRegistry::class)->registerCurrentNode();

        config(['gateway.verification_node_installation_id' => 'installation-b']);
        try {
            app(VerificationNodeRegistry::class)->registerCurrentNode();
            $this->fail('An independent installation must not reuse an active node ID.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Duplicate active', $e->getMessage());
        }
        $this->assertDatabaseCount('verification_nodes', 1);
        $this->assertSame('installation-a', VerificationNode::findOrFail('shared-node')->installation_id);

        config(['gateway.verification_node_id' => 'unique-node-b']);
        app(VerificationNodeRegistry::class)->registerCurrentNode();
        $this->assertDatabaseCount('verification_nodes', 2);
    }

    public function test_known_alive_owner_cannot_be_recovered_even_with_all_confirmations(): void
    {
        [$order, $lease] = $this->expiredLease();

        $this->assertSame(1, $this->recover($order, $lease, nodeShutdown: true));
        $this->assertSame($lease->token, $order->fresh()->verification_token);
        $this->assertDatabaseCount('verification_lease_recovery_audits', 0);
    }

    public function test_confirmed_dead_local_owner_is_recovered_and_audited(): void
    {
        [$order, $lease] = $this->expiredLease();
        $order->forceFill([
            'verification_pid' => 99999999,
            'verification_process_identity' => 'ps:'.str_repeat('a', 64),
        ])->save();

        $this->assertSame(0, $this->recover($order, $lease));
        $this->assertNull($order->fresh()->verification_token);
        $this->assertDatabaseCount('verification_lease_recovery_audits', 1);
    }

    public function test_unknown_local_owner_requires_confirmed_node_shutdown(): void
    {
        [$order, $lease] = $this->expiredLease();
        $order->forceFill(['verification_process_identity' => 'unknown:'.str_repeat('a', 64)])->save();

        $this->assertSame(1, $this->recover($order, $lease));
        $this->assertSame($lease->token, $order->fresh()->verification_token);
        $this->assertSame(0, $this->recover($order, $lease, nodeShutdown: true));
    }

    public function test_remote_owner_requires_confirmed_node_shutdown(): void
    {
        [$order, $lease] = $this->expiredLease();
        $order->forceFill([
            'verification_node_id' => 'remote-node',
            'verification_node_instance_id' => str_repeat('b', 64),
        ])->save();

        $this->assertSame(1, $this->recover($order, $lease));
        $this->assertSame(0, $this->recover($order, $lease, nodeShutdown: true));
    }

    public function test_wrong_token_generation_and_missing_operator_context_are_rejected(): void
    {
        [$order, $lease] = $this->expiredLease();
        $order->forceFill(['verification_pid' => 99999999, 'verification_process_identity' => 'ps:'.str_repeat('a', 64)])->save();

        $this->assertSame(1, $this->recover($order, $lease, token: 'wrong-token'));
        $this->assertSame(1, $this->recover($order, $lease, generation: $lease->generation + 1));
        $this->assertSame(1, $this->recover($order, $lease, operator: ''));
        $this->assertSame(1, $this->recover($order, $lease, reason: ''));
        $this->assertSame($lease->token, $order->fresh()->verification_token);
        $this->assertDatabaseCount('verification_lease_recovery_audits', 0);
    }

    public function test_paid_and_expired_orders_preserve_forensic_ownership_and_history(): void
    {
        [$paid, $paidLease] = $this->expiredLease();
        $paid->forceFill(['status' => Order::PAID, 'paid_at' => now(), 'provider_txn_id' => 'PAID-TXN'])->save();
        [$expired, $expiredLease] = $this->expiredLease();
        $expired->forceFill(['status' => Order::EXPIRED])->save();

        $this->assertSame(1, $this->recover($paid, $paidLease, nodeShutdown: true));
        $this->assertSame(1, $this->recover($expired, $expiredLease, nodeShutdown: true));
        $this->assertSame($paidLease->token, $paid->fresh()->verification_token);
        $this->assertSame('PAID-TXN', $paid->fresh()->provider_txn_id);
        $this->assertNotNull($paid->fresh()->paid_at);
        $this->assertSame($expiredLease->token, $expired->fresh()->verification_token);
        $this->assertDatabaseCount('verification_lease_recovery_audits', 0);
    }

    public function test_recovery_audit_is_append_only_through_the_model(): void
    {
        [$order, $lease] = $this->expiredLease();
        $order->forceFill(['verification_pid' => 99999999, 'verification_process_identity' => 'ps:'.str_repeat('a', 64)])->save();
        $this->assertSame(0, $this->recover($order, $lease));
        $audit = VerificationLeaseRecoveryAudit::firstOrFail();

        try {
            $audit->update(['reason' => 'rewritten']);
            $this->fail('Recovery audit updates must be rejected.');
        } catch (LogicException) {
            $this->assertSame('Provider process terminated', $audit->fresh()->reason);
        }
        try {
            $audit->delete();
            $this->fail('Recovery audit deletion must be rejected.');
        } catch (LogicException) {
            $this->assertDatabaseCount('verification_lease_recovery_audits', 1);
        }
    }

    /** @return array{Order, VerificationLease} */
    private function expiredLease(): array
    {
        $order = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
        $lease = $this->verificationLease($order);
        $order->forceFill(['verification_expires_at' => now()->subSecond()])->save();

        return [$order, $lease];
    }

    private function recover(
        Order $order,
        VerificationLease $lease,
        bool $nodeShutdown = false,
        ?string $token = null,
        ?int $generation = null,
        string $operator = 'ops@example.test',
        string $reason = 'Provider process terminated',
    ): int {
        return Artisan::call('orders:recover-verification-lease', [
            'order' => (string) $order->id,
            'execution' => $token ?? $lease->token,
            'generation' => (string) ($generation ?? $lease->generation),
            'operator' => $operator,
            'reason' => $reason,
            '--confirm-stopped' => true,
            '--confirm-node-shutdown' => $nodeShutdown,
        ]);
    }
}
