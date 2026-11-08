<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL commits each DDL statement independently. Resume completed
        // steps, including CREATE TABLE followed by interrupted index/FK DDL.
        if (! Schema::hasColumn('orders', 'webhook_event_sequence')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedInteger('webhook_event_sequence')->default(0);
            });
        }
        if (! Schema::hasTable('webhook_outbox')) {
            Schema::create('webhook_outbox', function (Blueprint $table) {
                $table->id();
                $table->uuid('event_id');
                $table->foreignId('order_id');
                $table->unsignedInteger('sequence');
                $table->string('transition_key', 100);
                $table->string('event', 64);
                $table->string('environment', 16);
                $table->json('payload');
                $table->string('status', 16)->default('pending');
                $table->timestamp('available_at')->nullable();
                $table->uuid('claim_token')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->unsignedInteger('dispatch_attempts')->default(0);
                $table->unsignedInteger('delivery_attempts')->default(0);
                $table->string('last_error', 64)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasColumns('webhook_outbox', [
            'id', 'event_id', 'order_id', 'sequence', 'transition_key', 'event',
            'environment', 'payload', 'status', 'available_at', 'claim_token',
            'claimed_at', 'queued_at', 'delivered_at', 'dispatch_attempts',
            'delivery_attempts', 'last_error', 'created_at', 'updated_at',
        ])) {
            throw new RuntimeException('Unexpected webhook_outbox schema. Keep writers/workers stopped, back up the database and reconcile the table with this migration before retrying. No history has been deleted.');
        }

        $this->ensureIndex('webhook_outbox', 'webhook_outbox_event_id_unique', ['event_id'], 'unique');
        $this->ensureIndex('webhook_outbox', 'webhook_outbox_transition_key_unique', ['transition_key'], 'unique');
        $this->ensureIndex('webhook_outbox', 'webhook_outbox_order_id_sequence_unique', ['order_id', 'sequence'], 'unique');
        // Leading available_at supports bounded dispatch without a filesort.
        $this->ensureIndex('webhook_outbox', 'outbox_dispatch_due', ['available_at', 'id'], 'index');

        $foreign = collect(Schema::getForeignKeys('webhook_outbox'))->first(fn ($key) => $key['name'] === 'webhook_outbox_order_id_foreign' || $key['columns'] === ['order_id']);
        if ($foreign === null) {
            Schema::table('webhook_outbox', function (Blueprint $table) {
                $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            });
        } elseif ($foreign['columns'] !== ['order_id'] || $foreign['foreign_table'] !== 'orders'
            || $foreign['foreign_columns'] !== ['id'] || strtolower($foreign['on_delete']) !== 'restrict') {
            throw new RuntimeException('Unexpected webhook outbox foreign key. Keep writers/workers stopped and reconcile webhook_outbox_order_id_foreign before retrying.');
        }

        if (! Schema::hasColumn('webhook_logs', 'event_id')) {
            Schema::table('webhook_logs', function (Blueprint $table) {
                $table->uuid('event_id')->nullable();
            });
        }
        $this->ensureIndex('webhook_logs', 'webhook_logs_event_identity_success', ['event_id', 'success'], 'index');
    }

    private function ensureIndex(string $table, string $name, array $columns, string $type): void
    {
        $existing = collect(Schema::getIndexes($table))->firstWhere('name', $name);
        if ($existing !== null) {
            if ($existing['columns'] !== $columns || (bool) $existing['unique'] !== ($type === 'unique')) {
                throw new RuntimeException("Unexpected {$name} index. Keep writers/workers stopped and reconcile the index before retrying.");
            }

            return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->{$type}($columns, $name));
    }

    public function down(): void
    {
        if (Schema::hasTable('webhook_outbox') && DB::table('webhook_outbox')->exists()) {
            throw new RuntimeException('Cannot roll back the webhook outbox while retained events exist. Stop writers/workers, export and reconcile events, and apply an approved archival policy before retrying.');
        }
        if (Schema::hasIndex('webhook_logs', 'webhook_logs_event_identity_success')) {
            Schema::table('webhook_logs', fn (Blueprint $table) => $table->dropIndex('webhook_logs_event_identity_success'));
        }
        if (Schema::hasColumn('webhook_logs', 'event_id')) {
            Schema::table('webhook_logs', fn (Blueprint $table) => $table->dropColumn('event_id'));
        }
        Schema::dropIfExists('webhook_outbox');
        if (Schema::hasColumn('orders', 'webhook_event_sequence')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('webhook_event_sequence'));
        }
    }
};
