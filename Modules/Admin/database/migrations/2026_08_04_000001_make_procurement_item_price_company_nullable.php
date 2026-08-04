<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PLATFORM procurement account (company_id NULL — it buys for ASAB, not for
 * one company) owns companyless catalog rows, so their price history has no
 * company either. The column was NOT NULL, so the first price change on a
 * platform item died on the constraint (2026-08-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_procurement_item_prices', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: narrowing back would reject the platform
        // rows written since.
    }
};
