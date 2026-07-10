<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linkage from the procurement dashboard catalog (asab_supplier_items) to the
 * mobile purchasing catalog (items): storeItem bridges into the Purchase world
 * and remembers the mobile row here so updateItem/destroyItem can sync it.
 * Nullable to stay sqlite-safe and non-breaking for pre-bridge rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_supplier_items', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_supplier_items', 'purchase_item_id')) {
                $t->uuid('purchase_item_id')->nullable()->after('supplier_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('asab_supplier_items', function (Blueprint $t) {
            if (Schema::hasColumn('asab_supplier_items', 'purchase_item_id')) {
                $t->dropColumn('purchase_item_id');
            }
        });
    }
};
