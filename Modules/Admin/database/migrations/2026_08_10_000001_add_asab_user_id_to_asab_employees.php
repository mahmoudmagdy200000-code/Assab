<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «الفلترة بالفرع بتُسقط مدير الفرع» (2026-08-10).
 *
 * A branch manager's roster row was only ever matched back to the dashboard
 * account by NAME — so a manager who moved branch (transfer-manager) kept the
 * old `branch_id` on `asab_employees` and vanished from
 * `GET /company/me/employees?branchId=<new branch>`, and a repair run created a
 * duplicate at the new branch instead of moving the original.
 *
 * This is the durable link: the dashboard user behind an employee row. Nullable
 * because the overwhelming majority of roster rows are plain employees with no
 * dashboard account at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_employees', function (Blueprint $t) {
            $t->uuid('asab_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('asab_employees', function (Blueprint $t) {
            $t->dropColumn('asab_user_id');
        });
    }
};
