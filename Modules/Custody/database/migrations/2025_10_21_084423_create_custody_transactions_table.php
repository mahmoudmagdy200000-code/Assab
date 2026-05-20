<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_manager_id')->constrained('branch_managers')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->enum('type', [
                'Cash Transfer',
                'Cash Handover',
                'Bank Transfer',
                'Expenses Deduction',
            ]);

            $table->decimal('amount', 12, 2);
            $table->boolean('is_cash_in')->default(true);

            // Related entities
            $table->uuid('related_custody_request_id')->nullable();
            $table->uuid('related_expense_id')->nullable();
            $table->uuid('related_handover_id')->nullable(); // For handover to another manager/owner

            // Foreign keys
            $table->foreign('related_custody_request_id')->references('id')->on('custody_requests')->nullOnDelete();
            $table->foreign('related_expense_id')->references('id')->on('expenses')->nullOnDelete();

            // Handover details (if type is Cash Handover)
            $table->enum('handover_recipient_type', ['Branch Manager', 'Brand Owner', 'Custody'])->nullable();
            $table->uuid('handover_recipient_id')->nullable();
            $table->enum('handover_method', ['Cash Handover', 'Bank Transfer'])->nullable();
            $table->timestamp('handover_date')->nullable();
            $table->text('handover_notes')->nullable();

            $table->timestamp('transaction_date');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('branch_manager_id');
            $table->index('type');
            $table->index('transaction_date');
            $table->index('is_cash_in');
            $table->index(['branch_manager_id', 'transaction_date'], 'idx_ct_bm_id_txn_date');
            $table->index('related_expense_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_transactions');
    }
};
