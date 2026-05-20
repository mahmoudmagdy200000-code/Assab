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
        if (Schema::hasTable('purchase_variances')) {
            return;
        }

        Schema::create('purchase_variances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('goods_receipt_id');
            $table->uuid('goods_receipt_item_id');
            $table->uuid('purchase_order_id');

            // Item details
            $table->string('item_name');
            $table->string('item_logo')->nullable();

            // Variance type
            $table->enum('variance_type', ['short', 'damage', 'both']);

            // Quantities
            $table->decimal('quantity_ordered', 12, 3);
            $table->decimal('quantity_received', 12, 3);
            $table->decimal('quantity_variance', 12, 3);

            // Quality
            $table->enum('quality_ordered', ['economy', 'standard', 'premium'])->nullable();
            $table->enum('quality_received', ['excellent', 'normal', 'poor'])->nullable();

            // Financial
            $table->decimal('unit_price', 12, 2);
            $table->decimal('variance_amount', 12, 2);

            // Action taken
            $table->enum('action', [
                'accept',
                'compensatory_order',
                'deduct_from_invoice',
            ])->nullable();

            // Status
            $table->enum('status', [
                'pending',
                'reported',
                'supplier_approved',
                'supplier_rejected',
                'escalated',
                'resolved',
                'closed',
            ])->default('pending');

            // Supplier response
            $table->uuid('responded_by')->nullable();
            $table->text('supplier_response')->nullable();
            $table->timestamp('responded_at')->nullable();

            // Escalation
            $table->boolean('is_escalated')->default(false);
            $table->text('escalation_reason')->nullable();
            $table->uuid('escalated_to')->nullable();
            $table->timestamp('escalated_at')->nullable();

            // Resolution
            $table->text('resolution_notes')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Evidence
            $table->json('photo_evidence')->nullable();
            $table->text('additional_notes')->nullable();

            // Deduction details
            $table->decimal('amount_to_deduct', 12, 2)->nullable();
            $table->text('deduction_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('goods_receipt_id');
            $table->index('goods_receipt_item_id');
            $table->index('purchase_order_id');
            $table->index('variance_type');
            $table->index('status');
            $table->index('action');

            // Foreign keys
            $table->foreign('goods_receipt_id')
                ->references('id')
                ->on('goods_receipts')
                ->cascadeOnDelete();

            $table->foreign('goods_receipt_item_id')
                ->references('id')
                ->on('goods_receipt_items')
                ->cascadeOnDelete();

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
        Schema::dropIfExists('purchase_variances');
    }
};
