<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier contracts with ASAB, not with one restaurant company: it has its
 * own mobile app and web portal and receives orders from every company on the
 * platform (client, 2026-07-20). That is a NULL company_id, so the column can no
 * longer be NOT NULL.
 *
 * The null is meaningful, not missing data:
 *   company_id = NULL  -> platform supplier, shared with every company
 *   company_id = <uuid> -> a supplier one company added for itself (unchanged)
 *
 * Read sharing is enforced by AsabSupplier::$tenantSharesPlatformRows; writes
 * still carry an explicit company_id predicate, so a company can order from a
 * platform supplier but cannot edit one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asab_suppliers', 'company_id')) {
            return;
        }

        Schema::table('asab_suppliers', function (Blueprint $t) {
            $t->uuid('company_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // No-op: reverting to NOT NULL would fail on every platform supplier.
    }
};
