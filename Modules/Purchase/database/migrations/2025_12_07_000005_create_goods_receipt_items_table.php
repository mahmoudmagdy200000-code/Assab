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
        if (Schema::hasTable('goods_receipt_items')) {
            return;
        }

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('goods_receipt_id');
            $table->uuid('purchase_order_item_id')->nullable();

            // Item details
            $table->uuid('item_id')->nullable();
            $table->string('item_name');
            $table->string('item_logo')->nullable();
            $table->enum('unit_of_measurement', ['kg', 'pk', 'unit', 'box', 'liter', 'piece'])->default('kg');

            // Quantities
            $table->decimal('quantity_ordered', 12, 3);
            $table->decimal('quantity_received', 12, 3);
            $table->decimal('quantity_variance', 12, 3)->default(0);

            // Quality
            $table->enum('quality_ordered', ['economy', 'standard', 'premium'])->nullable();
            $table->enum('quality_received', ['excellent', 'normal', 'poor'])->nullable();
            $table->boolean('has_quality_variance')->default(false);

            // Inspection details
            $table->decimal('temperature', 5, 2)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('photo')->nullable();
            $table->text('notes')->nullable();

            // Pricing
            $table->decimal('unit_price', 12, 2);
            $table->decimal('expected_total', 12, 2);
            $table->decimal('received_total', 12, 2);
            $table->decimal('variance_amount', 12, 2)->default(0);

            // Variance type if any
            $table->enum('variance_type', ['short', 'damage', 'both'])->nullable();

            // Is this an unlisted item (gift)?
            $table->boolean('is_unlisted')->default(false);
            $table->text('unlisted_reason')->nullable();
            $table->uuid('supplier_id')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('goods_receipt_id');
            $table->index('purchase_order_item_id');
            $table->index('item_id');

            // Foreign keys
            $table->foreign('goods_receipt_id')
                ->references('id')
                ->on('goods_receipts')
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
        Schema::dropIfExists('goods_receipt_items');
    }
};
