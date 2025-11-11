<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_variances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('goods_receipt_item_id')->constrained()->cascadeOnDelete();
            $table->enum('variance_type', ['short', 'damage', 'over']);
            $table->decimal('variance_quantity', 12, 3)->default(0);
            $table->decimal('variance_amount', 12, 2)->default(0);
            $table->enum('action_taken', ['accept_variance', 'create_compensatory_order', 'deduct_from_invoice'])->nullable();
            $table->foreignUuid('compensatory_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->decimal('deduction_amount', 12, 2)->nullable();
            $table->text('deduction_reason')->nullable();
            $table->string('photo_evidence')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('approved_by_supplier')->default(false);
            $table->text('supplier_response')->nullable();
            $table->timestamp('supplier_responded_at')->nullable();
            $table->timestamps();

            $table->index('goods_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_variances');
    }
};
