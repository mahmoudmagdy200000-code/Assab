<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabRestaurant;
use Modules\Branch\Models\Branch;

/**
 * «The branches of this brand» — the one resolver.
 *
 * A branch belongs to a brand either by the direct `asab_brand_id` link OR
 * through its restaurant (`asab_restaurant_id` → `asab_restaurants.brand_id`).
 * The second shape is the common one in production, so filtering on
 * `asab_brand_id` alone answers «no branches» and every brand-filtered screen
 * renders empty (2026-08-03 rule, first hit on the الأصول الثابتة column).
 *
 * Memoised per brand id for the request: a list endpoint that filters by brand
 * and then names the branches must not re-run the union query per row.
 */
class BrandBranchResolver
{
    /** @var array<string, string[]> */
    private array $memo = [];

    /**
     * Branch ids of a brand. Empty array = the brand genuinely has no branches
     * (callers must treat that as «match nothing», never as «no filter»).
     *
     * @return string[]
     */
    public function branchIds(string $brandId): array
    {
        return $this->memo[$brandId] ??= $this->query($brandId)->pluck('id')->all();
    }

    /**
     * Branch ids for several brands at once (brand-scoped accountant reads).
     *
     * @param  string[]  $brandIds
     * @return string[]
     */
    public function branchIdsForMany(array $brandIds): array
    {
        $out = [];
        foreach (array_unique($brandIds) as $brandId) {
            $out = array_merge($out, $this->branchIds($brandId));
        }

        return array_values(array_unique($out));
    }

    /**
     * Constrain a `branch_id`-keyed query to a brand. A null/blank brand id is
     * a no-op (the filter was simply not supplied).
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public function applyFilter($query, ?string $brandId, string $column = 'branch_id')
    {
        if ($brandId === null || $brandId === '') {
            return $query;
        }

        return $query->whereIn($column, $this->branchIds($brandId));
    }

    /**
     * The brand's branch rows (id + name + links), ordered by name.
     *
     * @param  string[]  $columns
     * @return \Illuminate\Database\Eloquent\Collection<int, Branch>
     */
    public function branches(string $brandId, array $columns = ['*'])
    {
        return $this->query($brandId)->orderBy('name')->get($columns);
    }

    private function query(string $brandId)
    {
        $restaurantIds = AsabRestaurant::withoutGlobalScopes()
            ->where('brand_id', $brandId)->pluck('id')->all();

        return Branch::query()->where(function ($q) use ($brandId, $restaurantIds) {
            $q->where('asab_brand_id', $brandId);
            if ($restaurantIds !== []) {
                $q->orWhereIn('asab_restaurant_id', $restaurantIds);
            }
        });
    }
}
