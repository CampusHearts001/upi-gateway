<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AdminAuditHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_actor_is_captured_for_all_audit_actions_and_survives_deletion(): void
    {
        $admin = User::create(['name' => 'Audit Admin', 'email' => 'actor@example.com', 'password' => 'password12345', 'role' => 'admin']);
        $log = AdminAuditLog::create(['admin_id' => $admin->id, 'action' => 'order_viewed', 'meta' => ['original' => true]]);
        $this->assertSame($admin->id, $log->fresh()->actor_admin_id);
        $admin->delete();
        $this->assertNull($log->fresh()->admin_id);
        $this->assertSame($admin->id, $log->fresh()->actor_admin_id);
        $this->assertSame(['original' => true], $log->fresh()->meta);
    }

    public function test_actor_snapshot_is_server_assigned_and_audit_history_is_append_only(): void
    {
        $admin = User::create(['name' => 'Audit Admin', 'email' => 'immutable-actor@example.com', 'password' => 'password12345', 'role' => 'admin']);
        $log = new AdminAuditLog(['admin_id' => $admin->id, 'action' => 'order_viewed']);
        $log->forceFill(['actor_admin_id' => 999999])->save();
        $this->assertSame($admin->id, $log->fresh()->actor_admin_id);
        $before = $log->fresh()->getAttributes();
        foreach (['actor_admin_id', 'meta', 'delete'] as $operation) {
            $error = null;
            try {
                $copy = $log->fresh();
                if ($operation === 'delete') {
                    $copy->delete();
                } else {
                    $copy->forceFill([$operation => $operation === 'meta' ? ['changed' => true] : 999999])->save();
                }
            } catch (LogicException $exception) {
                $error = $exception;
            }
            $this->assertInstanceOf(LogicException::class, $error);
            $this->assertSame($before, $log->fresh()->getAttributes());
        }
    }
}
