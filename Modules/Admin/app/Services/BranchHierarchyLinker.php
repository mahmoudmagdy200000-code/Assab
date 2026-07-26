<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Branch\Models\Branch;

/**
 * Heals a branch's ASAB hierarchy tags (`asab_company_id`, `asab_brand_id`,
 * `asab_restaurant_id`) from whatever link it already has.
 *
 * WHY (reported 2026-07-26): a brand/restaurant-scoped accountant only sees
 * operations whose branch resolves through `TenantBranchResolver` —
 * `branches WHERE asab_company_id = … AND asab_brand_id IN (…)`. A branch that
 * a mobile Branch Manager / Cashier actually submits from can carry
 * `asab_company_id` (or `asab_restaurant_id`) yet a NULL `asab_brand_id`
 * (legacy/mobile-origin branches, or partially-tagged ones). Its bridged
 * expense / shift operation then reaches only the head (scope=all, which
 * bypasses the branch filter and matches on company_id alone) and never the
 * responsible scoped accountant.
 *
 * Pure derivation — fills NULLs only, never overwrites an existing tag, and
 * only from the branch's own restaurant/brand link (no guessing). Runs at the
 * two-worlds bridge boundary so every bridged op lands on a resolvable branch.
 */
class BranchHierarchyLinker
{
    public function ensure(Branch $branch): void
    {
        // Already fully resolvable, or nothing to derive from.
        if ($branch->asab_brand_id !== null && $branch->asab_company_id !== null) {
            return;
        }
        if ($branch->asab_restaurant_id === null && $branch->asab_brand_id === null) {
            return;
        }

        $brandId = $branch->asab_brand_id;
        $companyId = $branch->asab_company_id;

        if ($branch->asab_restaurant_id !== null && ($brandId === null || $companyId === null)) {
            $restaurant = AsabRestaurant::withoutGlobalScopes()
                ->whereKey($branch->asab_restaurant_id)
                ->first(['id', 'brand_id', 'company_id']);
            if ($restaurant !== null) {
                $brandId ??= $restaurant->brand_id;
                $companyId ??= $restaurant->company_id;
            }
        }

        // Restaurant absent but a brand tag exists → the brand still yields the company.
        if ($companyId === null && $brandId !== null) {
            $companyId = AsabBrand::withoutGlobalScopes()->whereKey($brandId)->value('company_id');
        }

        if ($brandId === $branch->asab_brand_id && $companyId === $branch->asab_company_id) {
            return; // nothing new resolved
        }

        $branch->forceFill([
            'asab_brand_id' => $brandId,
            'asab_company_id' => $companyId,
        ])->save();
    }
}
