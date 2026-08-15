<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership flag for the mobile catalog row an asab supplier item points at.
 *
 * `items` is a tenant-less legacy table with a UNIQUE code, so a supplier item
 * whose code already exists (a brand raw-material upload, another supplier's
 * catalog) can never mint its own row. The bridge used to skip such items
 * entirely — no `supplier_items` price row, which is why a supplier with a full
 * dashboard catalog came back as an empty «choose supplier» list in the app.
 * It now LINKS to the shared row instead, and this flag records whether the row
 * is ours to rename or delete.
 *
 * Backfill: every already-linked row was linked because the bridge created (or
 * restored) it, so those are owned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_supplier_items', function (Blueprint $table) {
            $table->boolean('owns_purchase_item')->default(false);
        });

        DB::table('asab_supplier_items')
            ->whereNotNull('purchase_item_id')
            ->update(['owns_purchase_item' => true]);
    }

    public function down(): void
    {
        Schema::table('asab_supplier_items', function (Blueprint $table) {
            $table->dropColumn('owns_purchase_item');
        });
    }
};
