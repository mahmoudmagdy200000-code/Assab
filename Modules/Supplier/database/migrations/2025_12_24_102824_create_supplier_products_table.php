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
        if (Schema::hasTable('supplier_products')) {
            return;
        }

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_id');
            $table->uuid('item_id'); // Reference to items table from Purchase module
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('sku')->nullable();

            // Pricing
            $table->decimal('unit_price', 12, 2);
            $table->decimal('economy_price', 12, 2)->nullable();
            $table->decimal('standard_price', 12, 2)->nullable();
            $table->decimal('premium_price', 12, 2)->nullable();

            // Availability
            $table->boolean('is_available')->default(true);
            $table->decimal('min_order_quantity', 12, 3)->nullable();
            $table->decimal('max_order_quantity', 12, 3)->nullable();
            $table->decimal('stock_quantity', 12, 3)->default(0);

            // Delivery
            $table->integer('delivery_hours')->nullable();

            // Quality and Rating
            $table->enum('quality_level', ['economy', 'standard', 'premium'])->nullable();
            $table->decimal('rating', 3, 2)->nullable();

            // Additional Information
            $table->json('specifications')->nullable();
            $table->json('images')->nullable(); // Multiple images
            $table->json('categories')->nullable(); // Category IDs

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->cascadeOnDelete();

            $table->index('supplier_id');
            $table->index('item_id');
            $table->index('is_available');
            $table->index('quality_level');
            $table->unique(['supplier_id', 'item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
