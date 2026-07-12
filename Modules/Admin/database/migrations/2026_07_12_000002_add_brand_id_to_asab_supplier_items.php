<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T11.11 — catalog items carry the owning brand (PRC-3.1 «العلامة التجارية»),
 * set from the create/update modal. Nullable + non-FK, sqlite-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_supplier_items', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_supplier_items', 'brand_id')) {
                $t->uuid('brand_id')->nullable()->after('company_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('asab_supplier_items', function (Blueprint $t) {
            if (Schema::hasColumn('asab_supplier_items', 'brand_id')) {
                $t->dropColumn('brand_id');
            }
        });
    }
};
