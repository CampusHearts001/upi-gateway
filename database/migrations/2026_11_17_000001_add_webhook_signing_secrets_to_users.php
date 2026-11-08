<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds dedicated webhook signing secrets for Live and Sandbox environments,
     * separating webhook signature verification from merchant API authentication keys.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->after('api_secret');
            $table->text('sandbox_webhook_secret')->nullable()->after('sandbox_api_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['webhook_secret', 'sandbox_webhook_secret']);
        });
    }
};
