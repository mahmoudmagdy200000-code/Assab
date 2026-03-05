<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_custody_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cashier_id')->constrained('cashiers')->cascadeOnDelete();

            $table->enum('transaction_type', [
                'Handover Received', // Cash IN  — received from previous cashier/manager
                'Handover Sent',     // Cash OUT — given to next cashier/manager
            ]);

            $table->decimal('amount', 12, 2);
            $table->boolean('is_cash_in')->default(true);

            // Display name of the other party (from-cashier or to-cashier/manager)
            $table->string('counterpart_name')->nullable();

            $table->uuid('related_shift_id')->nullable();
            $table->uuid('related_handover_id')->nullable();

            $table->timestamp('transaction_date');
            $table->timestamps();
            $table->softDeletes();

            $table->index('cashier_id');
            $table->index('transaction_type');
            $table->index('transaction_date');
            $table->index('is_cash_in');
            $table->index(['cashier_id', 'transaction_date'], 'idx_cct_cashier_txn_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_custody_transactions');
    }
};
