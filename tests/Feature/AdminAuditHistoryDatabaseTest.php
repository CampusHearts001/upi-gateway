<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AdminAuditHistoryDatabaseTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        try {
            if ($this->app && Schema::hasTable('admin_audit_logs')) {
                // Disposable fixtures only; production rollback is guarded.
                DB::table('admin_audit_logs')->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_upgrade_backfills_only_known_actor_ids_and_preserves_other_history(): void
    {
        $migration = require database_path('migrations/2026_11_12_000001_preserve_admin_audit_actor_identifier.php');
        $migration->down();
        $admin = User::create(['name' => 'Prior Admin', 'email' => 'prior-actor@example.com', 'password' => 'password12345', 'role' => 'admin']);
        $ids = [];
        foreach ([$admin->id, null] as $actor) {
            $ids[] = DB::table('admin_audit_logs')->insertGetId([
                'admin_id' => $actor, 'action' => 'historical_action', 'meta' => json_encode(['keep' => 'history']),
                'ip' => '127.0.0.1', 'created_at' => '2026-10-01 12:00:00',
            ]);
        }
        $before = DB::table('admin_audit_logs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration->up();
        $migration->up(); // Rerunning after a completed column/backfill is safe.
        $rows = DB::table('admin_audit_logs')->orderBy('id')->get();
        $this->assertSame($admin->id, $rows[0]->actor_admin_id);
        $this->assertNull($rows[1]->actor_admin_id);
        $after = $rows->map(function ($row) {
            $attributes = (array) $row;
            unset($attributes['actor_admin_id']);

            return $attributes;
        })->all();
        $this->assertSame($before, $after);
        $admin->delete();
        $migration->up();
        $this->assertDatabaseHas('admin_audit_logs', ['id' => $ids[0], 'admin_id' => null, 'actor_admin_id' => $admin->id]);
        $error = null;
        try {
            $migration->down();
        } catch (RuntimeException $exception) {
            $error = $exception;
        }
        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertStringContainsString('retained administrator actor identifiers', $error->getMessage());
        $this->assertDatabaseCount('admin_audit_logs', 2);
        $this->assertTrue(Schema::hasColumn('admin_audit_logs', 'actor_admin_id'));
    }
}
