<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException as MigrationRuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'verification_node_instance_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('verification_node_instance_id', 64)->nullable();
            });
        }

        if (! Schema::hasTable('verification_nodes')) {
            Schema::create('verification_nodes', function (Blueprint $table) {
                $table->string('node_id', 128)->primary();
                $table->string('installation_id', 128);
                $table->string('boot_id', 128);
                $table->string('instance_id', 64);
                // Shared MySQL/MariaDB hosts may reject NOT NULL timestamps
                // without an explicit default under strict SQL modes.
                $table->timestamp('registered_at')->useCurrent();
                $table->timestamp('last_seen_at')->useCurrent();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('verification_lease_recovery_audits')) {
            Schema::create('verification_lease_recovery_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->string('order_no', 32);
                $table->unsignedBigInteger('previous_generation');
                $table->string('previous_node_id', 128)->nullable();
                $table->string('previous_node_instance_id', 64)->nullable();
                $table->unsignedBigInteger('previous_pid')->nullable();
                $table->string('previous_process_identity', 128)->nullable();
                $table->string('execution_token_hash', 64);
                $table->string('operator_identity', 190);
                $table->text('reason');
                $table->string('outcome', 32);
                $table->timestamp('recovered_at')->useCurrent();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('verification_lease_recovery_audits')
            && DB::table('verification_lease_recovery_audits')->exists()) {
            throw new MigrationRuntimeException('Cannot roll back retained verification lease recovery audit history.');
        }

        Schema::dropIfExists('verification_lease_recovery_audits');
        Schema::dropIfExists('verification_nodes');
        if (Schema::hasColumn('orders', 'verification_node_instance_id')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('verification_node_instance_id'));
        }
    }
};
