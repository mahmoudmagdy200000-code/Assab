<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The live board («مباشر») only ever held CASHIER shifts, so a branch manager
 * who started their workday in the mobile app (`branch_manager_shifts`) never
 * appeared on it. Mirroring the manager's day into `asab_shifts` needs a way to
 * tell the two apart — a manager row carries no sales of its own, is judged
 * against no shift window, and must never be closed through the SHF- pipeline.
 *
 * Additive: the column defaults to 'cashier', so every existing row (and every
 * writer that predates this change) keeps its current meaning without a
 * backfill pass. NOT NULL + default means no `NULL != 'x'` trap in the guards.
 *
 * MySQL note: adding a column with a default is INSTANT on 8.0.29+; on an older
 * server this rewrites/locks `asab_shifts` for the length of the copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_shifts', function (Blueprint $t) {
            $t->string('role', 24)->default('cashier')->after('cashier_name');
            // The live/history boards filter on (status, role) together.
            $t->index(['status', 'role'], 'asab_shifts_status_role_idx');
        });
    }

    public function down(): void
    {
        Schema::table('asab_shifts', function (Blueprint $t) {
            $t->dropIndex('asab_shifts_status_role_idx');
            $t->dropColumn('role');
        });
    }
};
