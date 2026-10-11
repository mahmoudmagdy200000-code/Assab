<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S1-10: immutable, revision-scoped physical cash count and its server calculation.
 *
 * Additive only. Every amount is INTEGER HALALAS (the column names carry the unit). A shift report
 * revision with no row here has NO count evidence (null, never an implied 0); historical reports are
 * deliberately not backfilled. The legacy cashier_shifts.cash_collected/closing_balance/variance
 * columns keep their documented AS-IS meaning and are neither read nor written as count evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_report_cash_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_revision_id')->constrained('shift_report_revisions')->restrictOnDelete();
            // The revision in which this count/calculation was established. A handover-only revision
            // carries the same figures forward (new report_revision_id, same counted_revision_id); a
            // changed report needs a fresh count and gets a new counted_revision_id.
            $table->foreignUuid('counted_revision_id')->constrained('shift_report_revisions')->restrictOnDelete();
            $table->foreignUuid('cashier_shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->unsignedBigInteger('gross_halalas');
            $table->unsignedBigInteger('cards_halalas');
            $table->unsignedBigInteger('apps_halalas');
            // Sum of confirmed receipts into this shift at report time (D2). Never configured opening.
            $table->unsignedBigInteger('confirmed_opening_halalas');
            // D11: physical cash included in the count that is still owned by the sender.
            $table->unsignedBigInteger('pending_incoming_counted_halalas')->default(0);
            $table->unsignedBigInteger('counted_halalas');
            $table->bigInteger('expected_halalas');
            // Signed: reconciled counted − expected. Negative is a shortage.
            $table->bigInteger('variance_halalas');
            $table->timestamps();

            $table->unique('report_revision_id', 'shift_cash_count_revision_unique');
            $table->index('cashier_shift_id', 'shift_cash_count_shift_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_report_cash_counts');
    }
};
