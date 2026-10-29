<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_debt_repayments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->foreignUuid('cashier_id')->constrained('cashiers')->restrictOnDelete();
            $table->foreignUuid('manager_id')->constrained('branch_managers')->restrictOnDelete();
            $table->unsignedBigInteger('amount_halalas');
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('pending_manager_confirmation');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignUuid('confirmed_by_id')->nullable()->constrained('branch_managers')->nullOnDelete();
            $table->timestamps();

            $table->index(['cashier_id', 'status'], 'shift_debt_repayment_cashier_idx');
            $table->index(['manager_id', 'status'], 'shift_debt_repayment_manager_idx');
            $table->index(['branch_id', 'created_at'], 'shift_debt_repayment_branch_time_idx');
        });

        if (Schema::hasTable('cashier_custody_transactions') && ! Schema::hasColumn('cashier_custody_transactions', 'debt_repayment_id')) {
            Schema::table('cashier_custody_transactions', function (Blueprint $table) {
                $table->foreignUuid('debt_repayment_id')->nullable()->constrained('shift_debt_repayments')->nullOnDelete();
            });
        }

        if (Schema::hasTable('personal_ledger_transactions') && ! Schema::hasColumn('personal_ledger_transactions', 'debt_repayment_id')) {
            Schema::table('personal_ledger_transactions', function (Blueprint $table) {
                $table->foreignUuid('debt_repayment_id')->nullable()->constrained('shift_debt_repayments')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_ledger_transactions') && Schema::hasColumn('personal_ledger_transactions', 'debt_repayment_id')) {
            Schema::table('personal_ledger_transactions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('debt_repayment_id');
            });
        }

        if (Schema::hasTable('cashier_custody_transactions') && Schema::hasColumn('cashier_custody_transactions', 'debt_repayment_id')) {
            Schema::table('cashier_custody_transactions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('debt_repayment_id');
            });
        }

        Schema::dropIfExists('shift_debt_repayments');
    }
};
