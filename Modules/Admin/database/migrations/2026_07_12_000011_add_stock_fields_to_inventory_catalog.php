<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T12.4 — the branch أصناف table + «تسجيل جرد» modal need an item code, a
 * reorder threshold (min_level) and the expected on-hand qty to derive the
 * كافٍ/منخفض/حرج stock status. All nullable, sqlite-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_inventory_catalog', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_inventory_catalog', 'code')) {
                $t->string('code', 40)->nullable()->after('name')->index();
            }
            if (! Schema::hasColumn('asab_inventory_catalog', 'min_level')) {
                $t->decimal('min_level', 12, 3)->nullable()->after('unit_price');
            }
            if (! Schema::hasColumn('asab_inventory_catalog', 'expected_qty')) {
                $t->decimal('expected_qty', 12, 3)->nullable()->after('min_level');
            }
        });
    }

    public function down(): void
    {
        Schema::table('asab_inventory_catalog', function (Blueprint $t) {
            foreach (['code', 'min_level', 'expected_qty'] as $col) {
                if (Schema::hasColumn('asab_inventory_catalog', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
