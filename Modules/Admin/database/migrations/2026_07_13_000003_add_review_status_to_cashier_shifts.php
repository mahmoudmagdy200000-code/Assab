<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-worlds feedback loop (WS1a): mirror the dashboard's shift-review decision
 * back onto the legacy `cashier_shifts` row so the mobile app can surface it.
 *
 * ADDITIVE ONLY. We deliberately do NOT touch `cashier_shifts.status` — its
 * MySQL enum has no approve/reject member, and reopening a completed till would
 * unfreeze finalized cash and break the handover chain. The decision rides its
 * own nullable columns; `status` stays 'completed' and `asab_shifts.status`
 * remains the source of truth for re-review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('cashier_shifts', 'review_status')) {
                // pending_review | approved | rejected — informational for the app.
                $table->string('review_status', 20)->nullable()->after('status');
            }
            if (! Schema::hasColumn('cashier_shifts', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('review_status');
            }
            if (! Schema::hasColumn('cashier_shifts', 'review_reason')) {
                $table->text('review_reason')->nullable()->after('reviewed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            foreach (['review_status', 'reviewed_at', 'review_reason'] as $col) {
                if (Schema::hasColumn('cashier_shifts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
