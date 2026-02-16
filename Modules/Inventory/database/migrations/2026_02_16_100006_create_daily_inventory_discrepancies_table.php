<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_inventory_discrepancies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('inventory_session_id');
            $table->uuid('inventory_item_id');
            $table->uuid('item_id');

            $table->decimal('opening_balance', 12, 3)->default(0);
            $table->decimal('purchases', 12, 3)->default(0);
            $table->decimal('sales', 12, 3)->default(0);
            $table->decimal('recorded_waste', 12, 3)->default(0);
            $table->decimal('net_transfer_in', 12, 3)->default(0);
            $table->decimal('net_transfer_out', 12, 3)->default(0);
            $table->decimal('theoretically_expected', 12, 3)->default(0);
            $table->decimal('actual', 12, 3)->default(0);
            $table->decimal('difference_quantity', 12, 3)->default(0);
            $table->decimal('difference_value_sar', 12, 2)->nullable();
            $table->string('discrepancy_type', 16)->nullable(); // shortage, over

            $table->timestamps();

            $table->foreign('inventory_session_id')->references('id')->on('inventory_sessions')->cascadeOnDelete();
            $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_inventory_discrepancies');
    }
};
