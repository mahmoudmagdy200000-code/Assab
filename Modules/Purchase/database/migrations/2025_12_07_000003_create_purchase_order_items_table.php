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
        if (Schema::hasTable('purchase_order_items')) {
            return;
        }

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id');

            // Item reference (polymorphic to support different item sources)
            $table->uuid('item_id')->nullable();
            $table->string('item_name');
            $table->string('item_logo')->nullable();
            $table->string('item_sku')->nullable();
            $table->string('category')->nullable();
            $table->string('subcategory')->nullable();

            // Quantity and unit
            $table->decimal('quantity_ordered', 12, 3);
            $table->decimal('quantity_confirmed', 12, 3)->nullable();
            $table->decimal('quantity_received', 12, 3)->nullable();
            $table->enum('unit_of_measurement', ['kg', 'pk', 'unit', 'box', 'liter', 'piece'])->default('kg');

            // Pricing
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);

            // Quality
            $table->enum('quality_ordered', ['economy', 'standard', 'premium'])->nullable();
            $table->enum('quality_received', ['excellent', 'normal', 'poor'])->nullable();

            // For internal transfers - availability info
            $table->decimal('available_in_source', 12, 3)->nullable();
            $table->decimal('remaining_balance', 12, 3)->nullable();
            $table->decimal('daily_consumption', 12, 3)->nullable();
            $table->decimal('weekend_forecast', 12, 3)->nullable();
            $table->date('next_supply_date')->nullable();

            // Expiry and temperature
            $table->date('expiry_date')->nullable();
            $table->decimal('temperature', 5, 2)->nullable();
            $table->boolean('cooling_status')->nullable();

            // Inspection
            $table->string('inspection_photo')->nullable();
            $table->text('inspection_notes')->nullable();

            // Status
            $table->enum('status', [
                'pending',
                'confirmed',
                'partial',
                'rejected',
                'received',
                'variance'
            ])->default('pending');

            // Modification tracking
            $table->decimal('original_quantity', 12, 3)->nullable();
            $table->decimal('new_quantity', 12, 3)->nullable();
            $table->text('modification_note')->nullable();
            $table->boolean('is_alternative')->default(false);
            $table->uuid('original_item_id')->nullable();

            // Gift items (unlisted items from supplier)
            $table->boolean('is_gift')->default(false);
            $table->text('gift_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('purchase_order_id');
            $table->index('item_id');
            $table->index('status');

            // Foreign keys
            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
