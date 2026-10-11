<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_handover_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('original_receipt_id')->constrained('cashier_shift_handover_receipts')->restrictOnDelete();
            $table->foreignUuid('original_attempt_id')->nullable()->constrained('shift_transfer_attempts')->restrictOnDelete();
            $table->string('original_request_type', 40);
            $table->uuid('original_request_id');
            $table->string('settlement_type', 40);
            $table->bigInteger('signed_delta_halalas');
            $table->text('reason');
            $table->string('operation_id', 100);
            $table->string('payload_hash', 64)->nullable();
            $table->string('sender_type', 30);
            $table->uuid('sender_id');
            $table->string('recipient_type', 30);
            $table->uuid('recipient_id');
            $table->string('actor_type', 30);
            $table->uuid('actor_id');
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['original_receipt_id', 'operation_id'], 'shift_settlement_receipt_op_unique');
            $table->index(['original_receipt_id', 'created_at'], 'shift_settlement_receipt_time_idx');
            $table->index(['branch_id', 'created_at'], 'shift_settlement_branch_time_idx');
        });

        Schema::table('cashier_custody_transactions', function (Blueprint $table) {
            $table->foreignUuid('settlement_id')->nullable()->constrained('shift_handover_settlements')->restrictOnDelete();
            $table->unique(['settlement_id', 'cashier_id', 'transaction_type'], 'custody_settlement_unique');
        });

        Schema::table('personal_ledger_transactions', function (Blueprint $table) {
            $table->foreignUuid('settlement_id')->nullable()->constrained('shift_handover_settlements')->restrictOnDelete();
            $table->unique(['settlement_id', 'branch_manager_id', 'transaction_type'], 'personal_ledger_settlement_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('shift_handover_settlements')->exists()
            || DB::table('cashier_custody_transactions')->whereNotNull('settlement_id')->exists()
            || DB::table('personal_ledger_transactions')->whereNotNull('settlement_id')->exists()) {
            throw new RuntimeException('Shift handover settlements or settlement transactions exist; migration rollback would destroy financial history.');
        }

        Schema::table('personal_ledger_transactions', function (Blueprint $table) {
            $table->dropUnique('personal_ledger_settlement_unique');
            $table->dropConstrainedForeignId('settlement_id');
        });

        Schema::table('cashier_custody_transactions', function (Blueprint $table) {
            $table->dropUnique('custody_settlement_unique');
            $table->dropConstrainedForeignId('settlement_id');
        });

        Schema::dropIfExists('shift_handover_settlements');
    }
};
