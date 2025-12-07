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
        if (Schema::hasTable('supplier_items')) {
            return;
        }

        Schema::create('supplier_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_id');
            $table->uuid('item_id');

            // Pricing
            $table->decimal('unit_price', 12, 2);
            $table->decimal('economy_price', 12, 2)->nullable();
            $table->decimal('standard_price', 12, 2)->nullable();
            $table->decimal('premium_price', 12, 2)->nullable();

            // Availability
            $table->boolean('is_available')->default(true);
            $table->decimal('min_order_quantity', 12, 3)->nullable();
            $table->decimal('max_order_quantity', 12, 3)->nullable();

            // Delivery
            $table->integer('delivery_hours')->nullable();

            // Rating for this specific item-supplier combination
            $table->decimal('rating', 3, 2)->nullable();

            $table->timestamps();

            // Indexes
            $table->index('supplier_id');
            $table->index('item_id');
            $table->unique(['supplier_id', 'item_id']);

            // Foreign keys
            $table->foreign('supplier_id')
                ->references('id')
                ->on('purchase_suppliers')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_items');
    }
};
