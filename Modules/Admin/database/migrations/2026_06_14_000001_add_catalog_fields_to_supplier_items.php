<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the supplier catalog (BACKEND_API_SPEC.md §6.2/6.3) with the full
 * item shape: max order quantity, availability flag, and delivery lead time.
 * Columns are nullable/defaulted to stay sqlite-safe and non-breaking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_supplier_items', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_supplier_items', 'max_qty')) {
                $t->integer('max_qty')->nullable()->after('min_qty');
            }
            if (! Schema::hasColumn('asab_supplier_items', 'available')) {
                $t->boolean('available')->default(true)->after('status');
            }
            if (! Schema::hasColumn('asab_supplier_items', 'lead_time_days')) {
                $t->integer('lead_time_days')->nullable()->after('available');
            }
        });
    }

    public function down(): void
    {
        Schema::table('asab_supplier_items', function (Blueprint $t) {
            foreach (['max_qty', 'available', 'lead_time_days'] as $col) {
                if (Schema::hasColumn('asab_supplier_items', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
