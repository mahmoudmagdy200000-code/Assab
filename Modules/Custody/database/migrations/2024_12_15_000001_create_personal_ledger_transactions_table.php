<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_ledger_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_manager_id')->constrained('branch_managers')->cascadeOnDelete();
            $table->enum('transaction_type', [
                'Total Sales',
                'Handover to Brand Owner',
                'Transfer to Custody'
            ]);
            $table->decimal('amount', 12, 2);
            $table->boolean('is_cash_in')->default(true);

            // Related entities (polymorphic or specific)
            $table->string('cashier_name')->nullable(); // For "Total Sales"
            $table->string('brand_owner_name')->nullable(); // For "Handover to Brand Owner"
            $table->uuid('related_shift_id')->nullable(); // Link to branch_manager_shifts
            $table->uuid('related_handover_id')->nullable(); // Link to cashier_shift_handovers

            $table->timestamp('transaction_date');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('branch_manager_id');
            $table->index('transaction_type');
            $table->index('transaction_date');
            $table->index(['branch_manager_id', 'transaction_date'], 'idx_plt_bm_id_txn_date');
            $table->index('is_cash_in');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_ledger_transactions');
    }
};
