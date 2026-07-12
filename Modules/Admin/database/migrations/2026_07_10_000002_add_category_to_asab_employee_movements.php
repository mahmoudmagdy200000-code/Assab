<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS ACC-7.4 — an employee ledger movement must say where it came from
 * («فرق مبيعات», «خصم هدر», «سلفة» …). Without a category the statement cannot
 * group its sources and the sales-variance debits are indistinguishable from
 * waste charges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_employee_movements', function (Blueprint $table) {
            $table->string('category', 32)->nullable()->after('movement_type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('asab_employee_movements', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
