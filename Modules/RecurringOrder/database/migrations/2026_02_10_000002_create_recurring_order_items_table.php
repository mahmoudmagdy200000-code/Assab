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
        if (Schema::hasTable('recurring_order_items')) {
            return;
        }

        Schema::create('recurring_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recurring_order_id');
            $table->uuid('item_id');
            $table->string('item_name')->nullable();
            $table->string('item_logo')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('quality')->nullable(); // economy, standard, premium
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->timestamps();

            $table->index('recurring_order_id');
            $table->index('item_id');

            $table->foreign('recurring_order_id')
                ->references('id')
                ->on('recurring_orders')
                ->cascadeOnDelete();

            $table->foreign('item_id')
                ->references('id')
                ->on('items')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_order_items');
    }
};
