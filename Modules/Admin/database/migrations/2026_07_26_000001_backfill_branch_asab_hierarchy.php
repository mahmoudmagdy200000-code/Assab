<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Branch\Models\Branch;

/**
 * Backfill the ASAB hierarchy tags on branches that carry a restaurant link but
 * a NULL brand/company (reported 2026-07-26): those branches were invisible to
 * a brand-scoped accountant, so a mobile Branch Manager's sales/expenses reached
 * only the head. Pure derivation from the restaurant — fills NULLs only, never
 * overwrites. Idempotent; safe to re-run. Driver-agnostic (query builder, so it
 * runs on both MySQL and the SQLite test DB).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches') || ! Schema::hasColumn('branches', 'asab_restaurant_id')) {
            return;
        }

        Branch::withoutGlobalScopes()
            ->whereNotNull('asab_restaurant_id')
            ->where(fn ($q) => $q->whereNull('asab_brand_id')->orWhereNull('asab_company_id'))
            ->orderBy('id')
            ->chunkById(500, function ($branches) {
                foreach ($branches as $branch) {
                    $restaurant = AsabRestaurant::withoutGlobalScopes()
                        ->whereKey($branch->asab_restaurant_id)
                        ->first(['id', 'brand_id', 'company_id']);
                    if ($restaurant === null) {
                        continue;
                    }
                    $branch->forceFill([
                        'asab_brand_id' => $branch->asab_brand_id ?? $restaurant->brand_id,
                        'asab_company_id' => $branch->asab_company_id ?? $restaurant->company_id,
                    ])->save();
                }
            });
    }

    public function down(): void
    {
        // Data backfill — the pre-fill NULL state is not recoverable; no-op.
    }
};
