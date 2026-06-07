<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unit price (halalas) for inventory catalog items — powers the daily
 * reconciliation variance valuation (MISSING_Dashboard §9). Nullable/additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asab_inventory_catalog') || Schema::hasColumn('asab_inventory_catalog', 'unit_price')) {
            return;
        }

        Schema::table('asab_inventory_catalog', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_price')->default(0); // halalas
        });
    }

    public function down(): void
    {
        // additive only — no-op
    }
};
