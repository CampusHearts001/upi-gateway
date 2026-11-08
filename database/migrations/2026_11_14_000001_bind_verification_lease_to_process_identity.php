<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'verification_node_id')) {
                $table->string('verification_node_id', 128)->nullable();
            }
            if (! Schema::hasColumn('orders', 'verification_process_identity')) {
                $table->string('verification_process_identity', 128)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = [];
            foreach (['verification_node_id', 'verification_process_identity'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
