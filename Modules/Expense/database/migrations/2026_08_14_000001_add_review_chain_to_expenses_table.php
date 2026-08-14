<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting 2026-08-14 — an expense now has TWO possible approval cycles:
 *
 *  - brand owner decides on the mobile app → terminal, the dashboard may not act;
 *  - accountant decides on the dashboard → the head accountant closes it.
 *
 * `expenses.status` is a MySQL ENUM('draft','pending','approved','rejected')
 * that the mobile app reads directly, so the intermediate states are carried
 * alongside it rather than added to it (adding an enum member needs a migration
 * on every environment and drifts from the PHP enum — SQLSTATE 1265 on prod).
 * `approval_stage` says WHERE in the chain the record is; the three `decided_*`
 * columns say WHO decided, which the mobile screens print («موافق عليه من …»)
 * and which `approved_by`/`rejected_by` cannot carry — they are FKs to the
 * legacy `users` table and a dashboard actor is an `asab_users` row.
 *
 * Additive and reversible; no backfill (a NULL stage reads as «fresh pending»,
 * which is what every existing row is).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('approval_stage', 32)->nullable()->after('status');
            $table->string('decided_by_name', 150)->nullable()->after('rejection_reason');
            $table->string('decided_by_role', 32)->nullable()->after('decided_by_name');
            $table->timestamp('decided_at')->nullable()->after('decided_by_role');

            // The brand-owner inbox and the head's «مرفوضة» list both filter on
            // (status, stage); the branch manager's Approval tab on the stage
            // alone.
            $table->index(['status', 'approval_stage'], 'expenses_status_stage_index');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_status_stage_index');
            $table->dropColumn(['approval_stage', 'decided_by_name', 'decided_by_role', 'decided_at']);
        });
    }
};
