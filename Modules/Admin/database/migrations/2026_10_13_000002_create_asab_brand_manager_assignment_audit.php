<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-2: append-only audit of brand-manager delegations.
 *
 * Every grant / change / revoke of a brand-manager's delegated brand set writes
 * one immutable row here. Rows are never updated or deleted when the assigned
 * set changes — the history of who delegated what, to whom, and when is
 * preserved (D2 provisioning requirement). This is evidence, not the live
 * authorization source (that is the brand_manager identity map + brand-manager
 * role, read live on every request).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asab_brand_manager_assignment_audit')) {
            return;
        }

        Schema::create('asab_brand_manager_assignment_audit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable()->index();
            $table->uuid('brand_manager_id');            // legacy mobile BrandManager id
            $table->uuid('dashboard_user_id')->nullable();
            $table->json('brand_ids')->nullable();       // the delegated set AFTER this action
            $table->string('action', 16);                // grant | change | revoke
            $table->uuid('granted_by')->nullable();       // acting dashboard user, when known
            $table->timestamp('created_at')->useCurrent();

            $table->index('brand_manager_id');
            // B-01: the generated name for this composite index is 71 characters, over the MySQL/MariaDB
            // 64-character identifier limit, so a fresh install failed on those servers. Explicit short name.
            $table->index(['brand_manager_id', 'created_at'], 'asab_bm_assign_audit_bm_created_idx');
        });
    }

    public function down(): void
    {
        // R-04: this is an append-only audit trail of delegations. Dropping it
        // once it holds history destroys that evidence (D-2 history-preservation
        // requirement), so refuse the rollback when any row exists. An empty
        // table rolls back cleanly.
        if (Schema::hasTable('asab_brand_manager_assignment_audit')
            && DB::table('asab_brand_manager_assignment_audit')->exists()) {
            throw new \RuntimeException(
                'Refusing to drop asab_brand_manager_assignment_audit: it holds append-only '
                .'delegation history that must be preserved. Do not roll back once used.'
            );
        }

        Schema::dropIfExists('asab_brand_manager_assignment_audit');
    }
};
