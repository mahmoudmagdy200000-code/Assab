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
        if (Schema::hasTable('purchase_invoices')) {
            return;
        }

        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('invoice_number')->unique();
            $table->uuid('goods_receipt_id');
            $table->uuid('purchase_order_id');
            $table->uuid('supplier_id')->nullable();

            // Invoice details
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('payment_terms')->default('Net 30 days');

            // Financial
            $table->decimal('amount_before_tax', 12, 2);
            $table->decimal('tax_rate', 5, 2)->default(15.00);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->decimal('deduction_amount', 12, 2)->default(0);
            $table->decimal('final_amount', 12, 2);

            // File
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_type')->nullable();
            $table->integer('file_size')->nullable();

            // Status
            $table->enum('status', [
                'pending',
                'approved',
                'paid',
                'partially_paid',
                'overdue',
                'disputed'
            ])->default('pending');

            // Deduction tracking
            $table->text('deduction_reason')->nullable();
            $table->json('deduction_details')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('invoice_number');
            $table->index('goods_receipt_id');
            $table->index('purchase_order_id');
            $table->index('supplier_id');
            $table->index('status');
            $table->index('due_date');

            // Foreign keys
            $table->foreign('goods_receipt_id')
                ->references('id')
                ->on('goods_receipts')
                ->cascadeOnDelete();

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->cascadeOnDelete();

            $table->foreign('supplier_id')
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
        Schema::dropIfExists('purchase_invoices');
    }
};
