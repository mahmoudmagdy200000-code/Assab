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
        if (Schema::hasTable('return_order_items')) {
            return;
        }

        Schema::create('return_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('return_order_id');
            $table->uuid('purchase_order_item_id')->nullable();

            // Item details
            $table->string('item_name');
            $table->string('item_logo')->nullable();

            // Quantity
            $table->decimal('return_quantity', 12, 3);
            $table->enum('unit_of_measurement', ['kg', 'pk', 'unit', 'box', 'liter', 'piece'])->default('kg');

            // Quality reason
            $table->enum('quality_reason', ['excellent', 'normal', 'poor']);

            // Pricing
            $table->decimal('unit_price', 12, 2);
            $table->decimal('return_amount', 12, 2);

            // Evidence files
            $table->json('files')->nullable();

            // Notes
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('return_order_id');
            $table->index('purchase_order_item_id');

            // Foreign keys
            $table->foreign('return_order_id')
                ->references('id')
                ->on('return_orders')
                ->cascadeOnDelete();

            $table->foreign('purchase_order_item_id')
                ->references('id')
                ->on('purchase_order_items')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_order_items');
    }
};
