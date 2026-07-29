<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Cashier\Models\Cashier;

/**
 * Brand isolation for the MOBILE app's branch pickers.
 *
 * The legacy mobile tables carry no brand column — `brand_owners` is a bare
 * login and `branches` links to the dashboard hierarchy through
 * `asab_brand_id` / `asab_restaurant_id`. So every mobile branch list used to
 * be a global `Branch::query()`, which showed a brand owner (and the branch
 * manager's "add cashier" picker) every brand's branches.
 *
 * This service resolves the branches one mobile actor may see:
 *  - brand owner   → the branches of the brands they own (identity map →
 *                    asab_users → asab_user_roles.brand_ids)
 *  - branch manager→ the branches of their own branch's brand
 *  - cashier       → their own branch
 *
 * Fail-closed: an actor whose brand cannot be resolved gets an EMPTY list, not
 * every branch. A legacy mobile account with no dashboard link is repaired with
 * `php artisan asab:backfill-identity-map` (or by re-provisioning it), never by
 * widening the scope here.
 */
class MobileBranchScopeService
{
    /**
     * Branch ids the authenticated mobile actor may see.
     *
     * @return string[]
     */
    public function visibleBranchIds(mixed $user): array
    {
        if ($user instanceof BrandOwner) {
            return $this->branchIdsForBrands($this->brandIdsForOwner($user));
        }

        if ($user instanceof BranchManager) {
            if (! $user->branch_id) {
                return [];
            }
            $brandId = Branch::whereKey($user->branch_id)->value('asab_brand_id');
            $ids = $brandId ? $this->branchIdsForBrands([$brandId]) : [];

            // The manager's own branch is always visible, even on a branch that
            // was never linked to a dashboard brand.
            return array_values(array_unique([...$ids, $user->branch_id]));
        }

        if ($user instanceof Cashier) {
            return $user->branch_id ? [$user->branch_id] : [];
        }

        return [];
    }

    /** Whether the actor may act on a branch (picker submissions, zero-trust). */
    public function canSee(mixed $user, ?string $branchId): bool
    {
        return $branchId !== null && in_array($branchId, $this->visibleBranchIds($user), true);
    }

    /**
     * Constrain a branches query (or any `branch_id`-keyed query) to the actor's brand.
     *
     * @param  string  $column  the branch-id column on the query's table
     */
    public function scope(mixed $query, mixed $user, string $column = 'id'): mixed
    {
        return $query->whereIn($column, $this->visibleBranchIds($user));
    }

    /**
     * The dashboard brands a mobile brand owner owns.
     *
     * @return string[]
     */
    private function brandIdsForOwner(BrandOwner $owner): array
    {
        $userId = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_OWNER)
            ->where('legacy_id', $owner->id)
            ->value('dashboard_id');

        // Same email fallback the identity-map backfill uses, for owners
        // provisioned before the map existed.
        $userId ??= AsabUser::withoutGlobalScope('tenant')->where('email', $owner->email)->value('id');

        if (! $userId) {
            return [];
        }

        return AsabUserRole::where('user_id', $userId)
            ->where('role_key', 'brand-owner')
            ->pluck('brand_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Branches under the given brands, directly (asab_brand_id) or through a
     * restaurant — branches imported before the brand column was backfilled
     * carry only asab_restaurant_id.
     *
     * @param  string[]  $brandIds
     * @return string[]
     */
    private function branchIdsForBrands(array $brandIds): array
    {
        if ($brandIds === []) {
            return [];
        }

        $restaurantIds = AsabRestaurant::withoutGlobalScope('tenant')
            ->whereIn('brand_id', $brandIds)
            ->pluck('id')
            ->all();

        return Branch::where(fn ($w) => $w
            ->whereIn('asab_brand_id', $brandIds)
            ->orWhereIn('asab_restaurant_id', $restaurantIds)
        )->pluck('id')->all();
    }
}
