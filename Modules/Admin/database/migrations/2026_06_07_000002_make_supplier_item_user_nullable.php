<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement can create catalog items that are not tied to a supplier-portal
 * user (COMPANY_DASHBOARD_API_SPEC.md §5.5). Make supplier_user_id nullable;
 * the legacy Supplier portal still sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asab_supplier_items', 'supplier_user_id')) {
            return;
        }
        Schema::table('asab_supplier_items', function (Blueprint $t) {
            $t->uuid('supplier_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // No-op: reverting to NOT NULL could fail on rows created without a supplier user.
    }
};
