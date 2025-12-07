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
        if (Schema::hasTable('compensatory_orders')) {
            return;
        }

        Schema::create('compensatory_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_number')->unique();
            $table->uuid('variance_id');
            $table->uuid('original_order_id');
            $table->uuid('new_order_id')->nullable(); // Reference to the new purchase order created

            // Item details
            $table->string('item_name');
            $table->string('item_logo')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->enum('quality', ['economy', 'standard', 'premium'])->nullable();

            // Reorder details
            $table->uuid('reorder_supplier_id')->nullable();
            $table->string('reorder_source')->nullable();
            $table->date('delivery_urgency_deadline');

            // Evidence
            $table->json('photo_evidence')->nullable();
            $table->text('additional_notes')->nullable();

            // Status
            $table->enum('status', [
                'pending',
                'ordered',
                'confirmed',
                'delivered',
                'completed',
                'canceled'
            ])->default('pending');

            $table->uuid('created_by');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('order_number');
            $table->index('variance_id');
            $table->index('original_order_id');
            $table->index('status');

            // Foreign keys
            $table->foreign('variance_id')
                ->references('id')
                ->on('purchase_variances')
                ->cascadeOnDelete();

            $table->foreign('original_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->cascadeOnDelete();

            $table->foreign('new_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->nullOnDelete();

            $table->foreign('reorder_supplier_id')
                ->references('id')
                ->on('purchase_suppliers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compensatory_orders');
    }
};
