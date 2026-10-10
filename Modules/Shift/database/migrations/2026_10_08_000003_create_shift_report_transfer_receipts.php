<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_report_aggregates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_type', 40);
            $table->uuid('source_id');
            $table->unsignedInteger('current_revision_number')->default(0);
            $table->timestamps();

            $table->unique(['source_type', 'source_id'], 'shift_report_aggregate_source_unique');
        });

        Schema::create('shift_report_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_aggregate_id')->constrained('shift_report_aggregates')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('created_by_type', 40);
            $table->uuid('created_by_id');
            $table->timestamps();

            $table->unique(['report_aggregate_id', 'revision_number'], 'shift_report_revision_number_unique');
        });

        Schema::create('branch_manager_cash_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_manager_shift_id')->constrained('branch_manager_shifts')->restrictOnDelete();
            $table->foreignUuid('destination_cashier_id')->constrained('cashiers')->restrictOnDelete();
            $table->foreignUuid('destination_cashier_shift_id')->constrained('cashier_shifts', indexName: 'bm_transfer_destination_shift_fk')->restrictOnDelete();
            $table->foreignUuid('report_revision_id')->nullable()->constrained('shift_report_revisions')->restrictOnDelete();
            $table->foreignUuid('created_by_id')->constrained('branch_managers')->restrictOnDelete();
            $table->decimal('requested_amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['branch_manager_shift_id', 'status'], 'bm_cash_transfer_source_status');
            $table->index(['destination_cashier_shift_id', 'status'], 'bm_cash_transfer_destination_status');
        });

        Schema::create('cashier_shift_handover_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cashier_shift_handover_id')->nullable()->constrained('cashier_shift_handovers', indexName: 'shift_receipt_handover_fk')->restrictOnDelete();
            $table->foreignUuid('branch_manager_cash_transfer_id')->nullable()->constrained('branch_manager_cash_transfers', indexName: 'shift_receipt_manager_transfer_fk')->restrictOnDelete();
            $table->foreignUuid('receiving_cashier_shift_id')->constrained('cashier_shifts', indexName: 'shift_receipt_receiving_shift_fk')->restrictOnDelete();
            $table->foreignUuid('receiving_cashier_id')->constrained('cashiers')->restrictOnDelete();
            $table->foreignUuid('report_revision_id')->constrained('shift_report_revisions')->restrictOnDelete();
            $table->decimal('confirmed_amount', 12, 2);
            $table->foreignUuid('confirmed_by_id')->constrained('cashiers')->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique('cashier_shift_handover_id', 'shift_receipt_handover_unique');
            $table->unique('branch_manager_cash_transfer_id', 'shift_receipt_manager_transfer_unique');
            $table->index(['receiving_cashier_shift_id', 'confirmed_at'], 'shift_receipt_destination_time');
        });

        Schema::table('cashier_shift_handovers', function (Blueprint $table) {
            $table->foreignUuid('report_revision_id')->nullable()->constrained('shift_report_revisions')->restrictOnDelete();
        });

        Schema::table('cashier_custody_transactions', function (Blueprint $table) {
            $table->foreignUuid('receipt_id')->nullable()->constrained('cashier_shift_handover_receipts')->restrictOnDelete();
            $table->unique(['receipt_id', 'cashier_id', 'transaction_type'], 'custody_receipt_effect_unique');
        });

        Schema::table('personal_ledger_transactions', function (Blueprint $table) {
            $table->foreignUuid('receipt_id')->nullable()->constrained('cashier_shift_handover_receipts')->restrictOnDelete();
            $table->unique('receipt_id', 'personal_ledger_receipt_unique');
        });
    }

    public function down(): void
    {
        Schema::table('personal_ledger_transactions', function (Blueprint $table) {
            $table->dropUnique('personal_ledger_receipt_unique');
            $table->dropConstrainedForeignId('receipt_id');
        });

        Schema::table('cashier_custody_transactions', function (Blueprint $table) {
            $table->dropUnique('custody_receipt_effect_unique');
            $table->dropConstrainedForeignId('receipt_id');
        });

        Schema::table('cashier_shift_handovers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('report_revision_id');
        });

        Schema::dropIfExists('cashier_shift_handover_receipts');
        Schema::dropIfExists('branch_manager_cash_transfers');
        Schema::dropIfExists('shift_report_revisions');
        Schema::dropIfExists('shift_report_aggregates');
    }
};
