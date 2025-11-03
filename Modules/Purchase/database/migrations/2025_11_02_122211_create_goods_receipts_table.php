<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('goods_receipts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
        $table->string('receipt_number')->unique();
        $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
        $table->unsignedBigInteger('received_by_id');
        $table->string('received_by_type'); // NEW
        $table->string('driver_name');
        $table->string('driver_contact');
        $table->string('vehicle_number');
        $table->timestamp('arrival_time');
        $table->enum('document_type', ['invoice', 'delivery_note', 'receipt_without_document']);
        $table->string('invoice_number')->nullable();
        $table->date('invoice_date')->nullable();
        $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
        $table->decimal('amount_before_tax', 12, 2)->default(0);
        $table->decimal('vat_amount', 12, 2)->default(0);
        $table->decimal('total_amount', 12, 2)->default(0);
        $table->string('payment_terms')->nullable();
        $table->date('due_date')->nullable();
        $table->string('invoice_file')->nullable();
        $table->integer('total_items_received')->default(0);
        $table->integer('total_variance_items')->default(0);
        $table->enum('status', ['draft', 'completed'])->default('draft');
        $table->text('notes')->nullable();
        $table->timestamps();
        $table->softDeletes();

        $table->index(['branch_id', 'status']);
        $table->index('created_at');
        $table->index(['received_by_id', 'received_by_type']);
    });
}

    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
