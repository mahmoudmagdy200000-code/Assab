<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandManager;
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
        // D-2: a BrandManager EXTENDS BrandOwner, so it must be resolved FIRST
        // and through its own explicit delegation — never the owner same-email
        // fallback below. Order matters: the BrandOwner branch would otherwise
        // capture a manager and hand it an owner's inherited brands.
        if ($user instanceof BrandManager) {
            return $this->branchIdsForBrands($this->brandIdsForManager($user));
        }

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

        // MEM-01: stale mobile tokens and remembered roles must not retain
        // branch access after the linked dashboard user is disabled.
        $dashboardUser = AsabUser::withoutGlobalScope('tenant')->find($userId);
        if ($dashboardUser === null || $dashboardUser->status !== 'active') {
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
     * The dashboard brands EXPLICITLY delegated to a mobile brand manager (D-2).
     *
     * Unlike the owner path there is NO same-email fallback: a manager is
     * authorised only through a verified `brand_manager` identity-map entry to a
     * dashboard user that carries a `brand-manager` role, and only for the brands
     * on that role that belong to the mapped user's company. Missing map, missing
     * role, empty brand set, or a cross-company brand all resolve to [] (fail
     * closed). Resolution is live on every request, so a revoked assignment
     * (deleted map or emptied role) denies access immediately — never via token
     * expiry or a cached scope.
     *
     * @return string[]
     */
    private function brandIdsForManager(BrandManager $manager): array
    {
        // Load the whole map row so the identity can be VALIDATED, not just
        // dereferenced (R-03). A soft-deleted (revoked) map is excluded by the
        // default scope, so a revoked manager resolves nothing.
        $map = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_MANAGER)
            ->where('legacy_id', $manager->id)
            ->first();

        if ($map === null || $map->dashboard_type !== 'asab_user' || ! $map->dashboard_id) {
            return [];
        }

        $user = AsabUser::withoutGlobalScope('tenant')->find($map->dashboard_id);
        // The dashboard user must exist AND be active — a disabled account grants
        // nothing — and the map's company tag must agree with the user's company.
        if ($user === null || $user->status !== 'active') {
            return [];
        }
        if ($map->company_id !== null && $map->company_id !== $user->company_id) {
            return [];
        }

        $brandIds = AsabUserRole::where('user_id', $user->id)
            ->where('role_key', 'brand-manager')
            ->where('scope', 'brand')
            ->pluck('brand_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($brandIds === []) {
            return [];
        }

        // Company consistency (fail-closed against a crafted cross-company brand):
        // keep only brands that belong to the mapped dashboard user's company.
        return AsabBrand::whereIn('id', $brandIds)
            ->where('company_id', $user->company_id)
            ->pluck('id')
            ->all();
    }

    /**
     * The dashboard brands a mobile brand actor may currently access (D-2 / FU-02
     * read authority). Brand owner → owned brands; brand manager → explicitly
     * delegated brands; anyone else (branch-level manager, cashier) has no brand
     * scope (their expense access, where any, is branch-scoped elsewhere).
     *
     * @return string[]
     */
    public function visibleBrandIds(mixed $user): array
    {
        if ($user instanceof BrandManager) {
            return $this->brandIdsForManager($user);
        }
        if ($user instanceof BrandOwner) {
            return $this->brandIdsForOwner($user);
        }

        return [];
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
