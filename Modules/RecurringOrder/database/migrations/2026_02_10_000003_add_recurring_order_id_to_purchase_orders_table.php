<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('purchase_orders')) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_orders', 'recurring_order_id')) {
                $table->uuid('recurring_order_id')->nullable()->after('parent_order_id');
                $table->index('recurring_order_id');
                $table->foreign('recurring_order_id')
                    ->references('id')
                    ->on('recurring_orders')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('purchase_orders')) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'recurring_order_id')) {
                $table->dropForeign(['recurring_order_id']);
                $table->dropColumn('recurring_order_id');
            }
        });
    }
};
