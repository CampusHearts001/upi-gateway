<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'original_expires_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('original_expires_at')->nullable()->after('expires_at');
            });
        }

        // Backfill existing rows with expires_at, or falling back to created_at
        DB::table('orders')
            ->whereNull('original_expires_at')
            ->whereNotNull('expires_at')
            ->update(['original_expires_at' => DB::raw('expires_at')]);

        DB::table('orders')
            ->whereNull('original_expires_at')
            ->whereNotNull('created_at')
            ->update(['original_expires_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'original_expires_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('original_expires_at');
            });
        }
    }
};
