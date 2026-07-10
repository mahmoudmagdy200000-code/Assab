<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linkage from dashboard suppliers (asab_suppliers) to the mobile-world
 * login-capable supplier (suppliers table, Modules\Supplier): storeSupplier
 * provisions the legacy row so the real order flow (OrderConsolidationService,
 * supplier_items pricing) can use dashboard-created suppliers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_suppliers', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_suppliers', 'legacy_supplier_id')) {
                $t->uuid('legacy_supplier_id')->nullable()->after('user_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('asab_suppliers', function (Blueprint $t) {
            if (Schema::hasColumn('asab_suppliers', 'legacy_supplier_id')) {
                $t->dropColumn('legacy_supplier_id');
            }
        });
    }
};
