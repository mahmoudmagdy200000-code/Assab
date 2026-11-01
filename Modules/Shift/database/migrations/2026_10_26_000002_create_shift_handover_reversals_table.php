<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_handover_reversals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->foreignUuid('original_handover_id')->constrained('cashier_shift_handovers')->restrictOnDelete();
            $table->foreignUuid('original_receipt_id')->nullable()->constrained('cashier_shift_handover_receipts')->nullOnDelete();
            $table->text('reversal_reason');
            $table->uuid('initiated_by_user_id');
            $table->uuid('confirmed_by_user_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('status', 32)->default('pending_sender_confirmation');
            $table->foreignUuid('corrected_handover_id')->nullable()->constrained('cashier_shift_handovers')->nullOnDelete();
            $table->timestamps();

            $table->index(['original_handover_id', 'status'], 'shift_reversal_handover_status_idx');
            $table->index(['branch_id', 'created_at'], 'shift_reversal_branch_created_idx');
            $table->index(['company_id', 'status'], 'shift_reversal_company_status_idx');
        });

        if (Schema::hasTable('cashier_custody_transactions') && ! Schema::hasColumn('cashier_custody_transactions', 'reversal_id')) {
            Schema::table('cashier_custody_transactions', function (Blueprint $table) {
                $table->foreignUuid('reversal_id')->nullable()->constrained('shift_handover_reversals')->nullOnDelete();
            });
        }

        if (Schema::hasTable('personal_ledger_transactions') && ! Schema::hasColumn('personal_ledger_transactions', 'reversal_id')) {
            Schema::table('personal_ledger_transactions', function (Blueprint $table) {
                $table->foreignUuid('reversal_id')->nullable()->constrained('shift_handover_reversals')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_ledger_transactions') && Schema::hasColumn('personal_ledger_transactions', 'reversal_id')) {
            Schema::table('personal_ledger_transactions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reversal_id');
            });
        }

        if (Schema::hasTable('cashier_custody_transactions') && Schema::hasColumn('cashier_custody_transactions', 'reversal_id')) {
            Schema::table('cashier_custody_transactions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reversal_id');
            });
        }

        Schema::dropIfExists('shift_handover_reversals');
    }
};
