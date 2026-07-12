<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T05.7 / T05.8 — the fixed-assets register.
 *
 * `public_id` was globally unique while being generated from a per-company
 * counter, so the second company to register an asset collided on `FA-0001`.
 * The key becomes composite; the id stays unique inside a company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asab_assets')) {
            return;
        }

        Schema::table('asab_assets', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_assets', 'serial')) {
                $t->string('serial', 64)->nullable()->after('inv_num');
            }
        });

        Schema::table('asab_assets', function (Blueprint $t) {
            $t->dropUnique('asab_assets_public_id_unique');
        });

        Schema::table('asab_assets', function (Blueprint $t) {
            $t->unique(['company_id', 'public_id'], 'asab_assets_company_public_id_unique');
            // The register filters by status and by category pill, always inside
            // one company — index the pairs the list endpoint actually queries.
            $t->index(['company_id', 'status'], 'asab_assets_company_status_index');
            $t->index(['company_id', 'category'], 'asab_assets_company_category_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('asab_assets')) {
            return;
        }

        Schema::table('asab_assets', function (Blueprint $t) {
            $t->dropUnique('asab_assets_company_public_id_unique');
            $t->dropIndex('asab_assets_company_status_index');
            $t->dropIndex('asab_assets_company_category_index');
        });

        Schema::table('asab_assets', function (Blueprint $t) {
            $t->unique('public_id', 'asab_assets_public_id_unique');
            if (Schema::hasColumn('asab_assets', 'serial')) {
                $t->dropColumn('serial');
            }
        });
    }
};
