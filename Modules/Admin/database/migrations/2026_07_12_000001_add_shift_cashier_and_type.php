<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T08 — persist the cashier identity, the sequential shift number/type and the
 * opening float on a shift (so close can no longer clobber the float), and give
 * employees a phone for the ACC-6.3 contact modal.
 *
 * Additive only — safe on both MySQL and SQLite (no column drops / FK changes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_shifts', function (Blueprint $t) {
            $t->uuid('cashier_employee_id')->nullable()->after('supervisor_name');
            $t->string('cashier_name', 200)->nullable()->after('cashier_employee_id');
            $t->string('shift_type', 16)->nullable()->after('cashier_name');   // صباحي | مسائي | الأول…
            $t->unsignedTinyInteger('shift_no')->nullable()->after('shift_type');
            $t->unsignedBigInteger('opening_float')->nullable()->after('cash_actual');
            // MOB-1.6 dedup: the legacy cashier shift this row bridges (if any).
            $t->uuid('legacy_shift_id')->nullable()->index()->after('opening_float');
        });

        Schema::table('asab_employees', function (Blueprint $t) {
            $t->string('phone', 32)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('asab_shifts', function (Blueprint $t) {
            $t->dropColumn(['cashier_employee_id', 'cashier_name', 'shift_type', 'shift_no', 'opening_float', 'legacy_shift_id']);
        });
        Schema::table('asab_employees', function (Blueprint $t) {
            $t->dropColumn('phone');
        });
    }
};
