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
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_manager_id')->constrained('branch_managers')->cascadeOnDelete();
            $table->enum('expense_type', ['quick_cash', 'single_invoice', 'grouped_invoice', 'pre_approval']);
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');

            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);

            $table->enum('payment_method', ['cash', 'supplier', 'custody'])->nullable();
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('branch_manager_id');
            $table->index('expense_type');
            $table->index('status');
            $table->index(['branch_manager_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
