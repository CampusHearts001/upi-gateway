<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'verification_token')) {
                    $table->string('verification_token', 64)->nullable();
                }
                if (! Schema::hasColumn('orders', 'verification_generation')) {
                    $table->unsignedBigInteger('verification_generation')->default(0);
                }
                if (! Schema::hasColumn('orders', 'verification_expires_at')) {
                    $table->timestamp('verification_expires_at')->nullable();
                }
                if (! Schema::hasColumn('orders', 'verification_pid')) {
                    $table->unsignedBigInteger('verification_pid')->nullable();
                }
                if (! Schema::hasColumn('orders', 'verification_started_at')) {
                    $table->timestamp('verification_started_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                $columns = [];
                foreach (['verification_token', 'verification_generation', 'verification_expires_at', 'verification_pid', 'verification_started_at'] as $column) {
                    if (Schema::hasColumn('orders', $column)) {
                        $columns[] = $column;
                    }
                }
                if (! empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
