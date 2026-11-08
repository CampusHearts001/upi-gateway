<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('admin_audit_logs', 'actor_admin_id')) {
            Schema::table('admin_audit_logs', function (Blueprint $table) {
                // Historical identifier, deliberately independent of the FK.
                $table->unsignedBigInteger('actor_admin_id')->nullable();
            });
        }

        // Preserve known identities only. A previously erased ID cannot be
        // reconstructed safely and must remain unknown, never guessed.
        DB::table('admin_audit_logs')->whereNull('actor_admin_id')->whereNotNull('admin_id')
            ->update(['actor_admin_id' => DB::raw('admin_id')]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('admin_audit_logs', 'actor_admin_id')) {
            return;
        }
        if (DB::table('admin_audit_logs')->whereNotNull('actor_admin_id')->exists()) {
            throw new RuntimeException('Cannot remove retained administrator actor identifiers. Stop writers, back up/export audit history and apply an approved archival policy before rollback.');
        }
        Schema::table('admin_audit_logs', fn (Blueprint $table) => $table->dropColumn('actor_admin_id'));
    }
};
