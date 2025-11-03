<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('purchase_returns', function (Blueprint $table) {
        $table->id();
        $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
        $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();
        $table->string('return_number')->unique();
        $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
        $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
        $table->unsignedBigInteger('created_by_id');
        $table->string('created_by_type'); // NEW
        $table->date('return_date');
        $table->decimal('total_return_amount', 12, 2)->default(0);
        $table->enum('required_action', ['replacement', 'cash_refund', 'credit_future']);
        $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'closed', 'resolved'])->default('draft');
        $table->text('additional_notes')->nullable();
        $table->unsignedBigInteger('approved_by_id')->nullable();
        $table->string('approved_by_type')->nullable(); // NEW
        $table->timestamp('approved_at')->nullable();
        $table->unsignedBigInteger('rejected_by_id')->nullable();
        $table->string('rejected_by_type')->nullable(); // NEW
        $table->timestamp('rejected_at')->nullable();
        $table->text('rejection_reason')->nullable();
        $table->boolean('escalated_to_brand_owner')->default(false);
        $table->text('escalation_reason')->nullable();
        $table->unsignedBigInteger('resolved_by_id')->nullable();
        $table->string('resolved_by_type')->nullable(); // NEW
        $table->timestamp('resolved_at')->nullable();
        $table->string('resolution_type')->nullable();
        $table->decimal('refund_amount', 12, 2)->nullable();
        $table->string('refund_method')->nullable();
        $table->text('refund_note')->nullable();
        $table->string('refund_file')->nullable();
        $table->timestamps();
        $table->softDeletes();

        $table->index(['branch_id', 'status']);
        $table->index('created_at');
        $table->index(['created_by_id', 'created_by_type']);
    });
}


    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
