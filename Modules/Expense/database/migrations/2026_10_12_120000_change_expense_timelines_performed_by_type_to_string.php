<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G-1: let expense_timelines.performed_by_type record a brand manager.
 *
 * The column was enum('branch_manager','brand_owner','system'). A brand-manager
 * decision (EXP-06) has no representable value there — on MySQL/MariaDB the
 * write is rejected (SQLSTATE 1265 "Data truncated"), and on SQLite the enum is
 * a varchar with a CHECK (… in (…)) that rejects it too. Converting the column
 * to a plain string(32) lifts the constraint on both drivers, mirroring the
 * proven precedent migration
 * 2026_07_30_000002_extend_personal_ledger_transactions_for_expenses
 * (enum -> string for personal_ledger_transactions.transaction_type).
 *
 * Additive and backward-compatible: the three existing values still fit, no row
 * is read or rewritten, and the PHP enum keeps them. The new 'brand_manager'
 * value is written only by code shipped together with (and deployed AFTER) this
 * migration.
 *
 * Deploy ordering: run this migration BEFORE deploying the code that writes
 * 'brand_manager'. On a large expense_timelines table the MySQL MODIFY COLUMN
 * takes a brief metadata/table lock — schedule it in a migration window.
 *
 * Rollback limitation (down): restoring the 3-value enum is only safe while no
 * row holds 'brand_manager'. Any such row would be rejected by the restored
 * constraint; the down() does NOT rewrite history (that would be destructive),
 * so a rollback after brand-manager rows exist must be handled manually.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_timelines', function (Blueprint $table) {
            $table->string('performed_by_type', 32)->change();
        });
    }

    public function down(): void
    {
        // Preflight (FU-03): abort BEFORE any schema change if rows already hold
        // the new value. Do NOT rely on DB strict mode to reject the narrowed
        // enum — a non-strict MySQL/MariaDB would silently coerce/truncate
        // 'brand_manager' and corrupt historical attribution. This migration
        // never coerces or deletes historical rows.
        if (DB::table('expense_timelines')->where('performed_by_type', 'brand_manager')->exists()) {
            throw new \RuntimeException(
                'Cannot roll back 2026_10_12_120000: expense_timelines still has rows with '
                .'performed_by_type = "brand_manager". Reassign or archive them deliberately '
                .'before narrowing the column; this rollback will not rewrite history.'
            );
        }

        Schema::table('expense_timelines', function (Blueprint $table) {
            $table->enum('performed_by_type', ['branch_manager', 'brand_owner', 'system'])->change();
        });
    }
};
