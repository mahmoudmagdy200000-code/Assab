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
        Schema::create('invoice_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignId('grouped_invoice_id')->nullable()->constrained('grouped_invoices')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            $table->string('invoice_number', 100);
            $table->date('issue_date');
            $table->boolean('is_tax_invoice')->default(false);
            $table->string('tax_id', 50)->nullable();

            $table->enum('payment_type', ['full', 'partial', 'deferred'])->nullable();
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->date('due_date')->nullable();

            $table->timestamps();

            $table->index('expense_id');
            $table->index('grouped_invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_details');
    }
};
