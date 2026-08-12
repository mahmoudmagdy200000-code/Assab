<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;

/**
 * The companies a dashboard user may read, derived from the ids their role
 * assignment already names.
 *
 * «العلامة هي الشركة»: admin "Add Brand" gives every brand a company of its own
 * (BrandCompanyResolver), so a second brand — or a restaurant of one — assigned
 * to an accountant lives in a second company. Pinning that accountant to the
 * single `asab_users.company_id` made the assignment inert: the users screen
 * showed the restaurant, the accountant's portal showed neither it nor its
 * brand, and nothing errored (reported 2026-08-11).
 *
 * This resolves the SET instead. It widens only along ids an admin explicitly
 * assigned — an unassigned brand still resolves to nothing, so tenant isolation
 * stays fail-closed.
 */
class TenantCompanyResolver
{
    /**
     * @param  string[]  $brandIds
     * @param  string[]  $restaurantIds
     * @return string[] primary company first, then the assigned brands' companies
     */
    public function resolve(?string $primaryCompanyId, array $brandIds, array $restaurantIds): array
    {
        $ids = $primaryCompanyId !== null ? [$primaryCompanyId] : [];

        // withoutGlobalScope('tenant'): this runs while the tenant context is
        // still being built, so the scope would filter by the very answer we are
        // computing (and the brand of another company would drop out).
        if ($brandIds !== []) {
            $ids = array_merge($ids, AsabBrand::withoutGlobalScope('tenant')
                ->whereIn('id', $brandIds)->pluck('company_id')->all());
        }

        if ($restaurantIds !== []) {
            $ids = array_merge($ids, AsabRestaurant::withoutGlobalScope('tenant')
                ->whereIn('id', $restaurantIds)->pluck('company_id')->all());
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
