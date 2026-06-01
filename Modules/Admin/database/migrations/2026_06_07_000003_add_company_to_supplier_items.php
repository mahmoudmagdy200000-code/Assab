<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-isolate procurement-owned catalog items (COMPANY_DASHBOARD_API_SPEC.md
 * §5.5). asab_supplier_items is shared with the legacy supplier portal (keyed by
 * supplier_user_id, company_id null); company procurement items carry company_id
 * so writes can be scoped to the owning tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('asab_supplier_items', 'company_id')) {
            return;
        }
        Schema::table('asab_supplier_items', function (Blueprint $t) {
            $t->uuid('company_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('asab_supplier_items', 'company_id')) {
            Schema::table('asab_supplier_items', function (Blueprint $t) {
                $t->dropColumn('company_id');
            });
        }
    }
};
