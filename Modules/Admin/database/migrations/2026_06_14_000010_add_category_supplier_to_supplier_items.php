<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement-owned catalog items (COMPANY_DASHBOARD_API_SPEC.md §5.6) carry a
 * free-text `category` and an optional `supplier_id` link to asab_suppliers.
 * Columns are nullable to stay sqlite-safe and non-breaking with the existing
 * supplier-portal rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_supplier_items', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_supplier_items', 'category')) {
                $t->string('category', 80)->nullable()->after('name');
            }
            if (! Schema::hasColumn('asab_supplier_items', 'supplier_id')) {
                $t->uuid('supplier_id')->nullable()->after('company_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('asab_supplier_items', function (Blueprint $t) {
            foreach (['category', 'supplier_id'] as $col) {
                if (Schema::hasColumn('asab_supplier_items', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
