<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T12.11 — employee numbers are unique per company; a unique index makes the
 * generate-and-retry loop in BranchCompanyController safe under concurrency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_employees', function (Blueprint $t) {
            $t->unique(['company_id', 'emp_number'], 'asab_employees_company_emp_unique');
        });
    }

    public function down(): void
    {
        Schema::table('asab_employees', function (Blueprint $t) {
            $t->dropUnique('asab_employees_company_emp_unique');
        });
    }
};
