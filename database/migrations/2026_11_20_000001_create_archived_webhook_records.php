<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('archived_webhook_logs')) {
            Schema::create('archived_webhook_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->string('event');
                $table->uuid('event_id')->nullable();
                $table->string('url', 500);
                $table->json('payload');
                $table->unsignedSmallInteger('response_code')->nullable();
                $table->text('response_body')->nullable();
                $table->boolean('success')->default(false);
                $table->unsignedTinyInteger('attempt')->default(1);
                $table->string('error_label')->nullable();
                $table->timestamps();

                $table->index('order_id', 'archived_webhook_logs_order_id_index');
                $table->index(['order_id', 'event', 'success'], 'archived_logs_order_event_success');
                $table->index(['event_id', 'success'], 'archived_logs_event_identity_success');
            });
        }

        if (! Schema::hasTable('archived_webhook_payloads')) {
            Schema::create('archived_webhook_payloads', function (Blueprint $table) {
                $table->id();
                $table->uuid('event_id')->unique();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->json('payload');
                $table->timestamp('archived_at');

                $table->index('order_id', 'archived_payloads_order_id_index');
            });
        }

        if (Schema::hasTable('webhook_outbox')) {
            Schema::table('webhook_outbox', function (Blueprint $table) {
                $table->json('payload')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('webhook_outbox')) {
            Schema::table('webhook_outbox', function (Blueprint $table) {
                $table->json('payload')->nullable(false)->change();
            });
        }

        Schema::dropIfExists('archived_webhook_payloads');
        Schema::dropIfExists('archived_webhook_logs');
    }
};
