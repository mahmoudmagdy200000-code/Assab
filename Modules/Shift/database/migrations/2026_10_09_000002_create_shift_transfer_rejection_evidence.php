<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S1-10 / D11: structured evidence of the physical amount a recipient counted when rejecting a transfer
 * request for amount correction (for example requested 500, physically 480). The amount is pending
 * incoming linked to the request, never a surplus, and the sender stays responsible. Additive and
 * append-only; the request and its earlier evidence are never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_transfer_rejection_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cashier_shift_handover_id')->nullable()->constrained('cashier_shift_handovers', indexName: 'transfer_rejection_handover_fk')->restrictOnDelete();
            $table->foreignUuid('branch_manager_cash_transfer_id')->nullable()->constrained('branch_manager_cash_transfers', indexName: 'transfer_rejection_manager_request_fk')->restrictOnDelete();
            $table->string('recipient_type', 20);
            $table->uuid('recipient_id');
            // The recipient cashier's shift whose count includes this cash, when it was unambiguous.
            $table->foreignUuid('receiving_cashier_shift_id')->nullable()->constrained('cashier_shifts', indexName: 'transfer_rejection_receiving_shift_fk')->restrictOnDelete();
            $table->unsignedBigInteger('requested_halalas');
            $table->unsignedBigInteger('physical_halalas');
            $table->string('correction_reason', 30);
            $table->timestamp('rejected_at');
            $table->timestamps();

            $table->index(['cashier_shift_handover_id', 'rejected_at'], 'shift_rejection_evidence_handover_idx');
            $table->index(['branch_manager_cash_transfer_id', 'rejected_at'], 'shift_rejection_evidence_transfer_idx');
            $table->index(['receiving_cashier_shift_id'], 'shift_rejection_evidence_receiving_idx');
            $table->index(['recipient_type', 'recipient_id', 'rejected_at'], 'shift_rejection_evidence_recipient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_transfer_rejection_evidence');
    }
};
