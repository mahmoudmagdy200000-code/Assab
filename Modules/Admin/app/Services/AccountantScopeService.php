<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Collection;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch as MobileBranch;

/**
 * Resolves accountant ↔ restaurant coverage.
 *
 * An accountant is assigned at BRAND level (client meeting 2026-07-22), so the
 * restaurants they are responsible for are every restaurant of their assigned
 * brands — NOT the (now usually empty) `restaurant_ids` array. Reading coverage
 * straight off `restaurant_ids` is why the dashboard showed "zero restaurants /
 * zero accountants" for brand-scoped accountants. Legacy restaurant-scoped
 * assignments (restaurant_ids populated) are still honoured.
 *
 * Both helpers load their source rows once and filter in memory, so a caller
 * looping restaurants/accountants does not trigger a query per row.
 */
class AccountantScopeService
{
    /** @var Collection<int,AsabRestaurant>|null id, name, brand_id only */
    private ?Collection $restaurantsCache = null;

    /** @var Collection<int,AsabUserRole>|null accountant assignments */
    private ?Collection $accountantsCache = null;

    /** @return Collection<int,AsabRestaurant> */
    private function allRestaurants(): Collection
    {
        return $this->restaurantsCache ??= AsabRestaurant::query()->get(['id', 'name', 'brand_id']);
    }

    /** @return Collection<int,AsabUserRole> */
    private function accountantAssignments(): Collection
    {
        return $this->accountantsCache ??= AsabUserRole::query()
            ->where('role_key', 'accountant')
            ->get(['user_id', 'brand_ids', 'restaurant_ids']);
    }

    /**
     * Restaurants a single accountant assignment is responsible for
     * (brand-scoped ∪ any legacy restaurant-scoped ids).
     *
     * @return Collection<int,AsabRestaurant>
     */
    public function restaurantsForAssignment(?AsabUserRole $assignment): Collection
    {
        if (! $assignment) {
            return collect();
        }

        $brandIds = $assignment->brand_ids ?? [];
        $restaurantIds = $assignment->restaurant_ids ?? [];

        return $this->allRestaurants()->filter(
            fn (AsabRestaurant $r) => in_array($r->brand_id, $brandIds, true)
                || in_array($r->id, $restaurantIds, true)
        )->values();
    }

    /** @var array<string,AsabUser|null> per-request memo keyed by mobile branch id */
    private static array $responsibleAccountantCache = [];

    /**
     * The dashboard accountant responsible for a MOBILE branch (via its
     * asab_brand_id / asab_restaurant_id link), or null when the branch is not
     * linked or no accountant covers it. Used by the mobile Final Handover
     * screen ("Waiting for approval from …") — FR-SAL: the manager's daily
     * close is approved by the responsible accountant on the Sales dashboard.
     */
    public function responsibleAccountantForMobileBranch(MobileBranch $branch): ?AsabUser
    {
        if (array_key_exists($branch->id, self::$responsibleAccountantCache)) {
            return self::$responsibleAccountantCache[$branch->id];
        }

        return self::$responsibleAccountantCache[$branch->id] = $this->resolveResponsibleAccountant($branch);
    }

    private function resolveResponsibleAccountant(MobileBranch $branch): ?AsabUser
    {
        $brandId = $branch->asab_brand_id;
        $restaurantId = $branch->asab_restaurant_id;

        if (! $brandId && $restaurantId) {
            $brandId = $this->allRestaurants()->firstWhere('id', $restaurantId)?->brand_id;
        }

        if (! $brandId && ! $restaurantId) {
            return null;
        }

        $assignments = AsabUserRole::query()
            ->where('role_key', 'accountant')
            ->get(['user_id', 'scope', 'brand_ids', 'restaurant_ids']);

        $scopedUserIds = $assignments->filter(
            fn (AsabUserRole $a) => in_array($brandId, $a->brand_ids ?? [], true)
                || in_array($restaurantId, $a->restaurant_ids ?? [], true)
        )->pluck('user_id');

        $accountant = $scopedUserIds->isEmpty() ? null : AsabUser::query()
            ->whereIn('id', $scopedUserIds)
            ->when($branch->asab_company_id, fn ($q) => $q->where('company_id', $branch->asab_company_id))
            ->orderBy('created_at')
            ->first();

        if ($accountant) {
            return $accountant;
        }

        // scope='all' accountants cover every brand of THEIR company only, so a
        // company link on the branch is required before matching them.
        if (! $branch->asab_company_id) {
            return null;
        }

        $allScopeUserIds = $assignments->where('scope', 'all')->pluck('user_id');

        return $allScopeUserIds->isEmpty() ? null : AsabUser::query()
            ->whereIn('id', $allScopeUserIds)
            ->where('company_id', $branch->asab_company_id)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Number of accountants responsible for each given restaurant, keyed by id.
     *
     * @param  iterable<AsabRestaurant>  $restaurants
     * @return array<string,int>
     */
    public function accountantCounts(iterable $restaurants): array
    {
        $assignments = $this->accountantAssignments();

        $counts = [];
        foreach ($restaurants as $restaurant) {
            $counts[$restaurant->id] = $assignments->filter(
                fn (AsabUserRole $a) => in_array($restaurant->brand_id, $a->brand_ids ?? [], true)
                    || in_array($restaurant->id, $a->restaurant_ids ?? [], true)
            )->count();
        }

        return $counts;
    }
}
