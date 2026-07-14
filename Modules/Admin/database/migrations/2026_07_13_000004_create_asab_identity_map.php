<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-world identity map (WS2): an explicit dashboard-id ↔ legacy-id table so
 * the two worlds stop relying on the fragile email/legacy_* coupling. Records
 * WHICH legacy mobile row (cashiers / suppliers / brand_owners) a dashboard
 * entity (asab_employees / asab_suppliers / asab_users) corresponds to.
 *
 * Rollout is incremental: this table is dual-written by the provisioning
 * services alongside the existing legacy_* mirror columns; readers are cut over
 * later, and the mirror columns are dropped last. It records linkage only — it
 * does NOT unify credentials (asab vs legacy password stores remain separate).
 * branch_manager is intentionally out of v1 (no legacy provisioning exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asab_identity_map', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable()->index(); // tenant tag (legacy tables carry none)
            $table->string('entity_type', 24);   // cashier | supplier | brand_owner
            $table->string('dashboard_type', 32); // asab_employee | asab_supplier | asab_user
            $table->uuid('dashboard_id');
            $table->string('legacy_type', 32);   // cashier | supplier | brand_owner
            $table->uuid('legacy_id');
            $table->string('match_method', 12)->default('email'); // id | email — how the link was established
            $table->string('linked_email')->nullable();           // audits the fragile coupling
            $table->string('source', 16)->default('provisioning'); // provisioning | backfill
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One dashboard entity ↔ one legacy row, per entity type.
            $table->unique(['entity_type', 'dashboard_id']);
            $table->unique(['entity_type', 'legacy_id']);
            $table->index(['legacy_type', 'legacy_id']); // reverse lookups
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_identity_map');
    }
};
