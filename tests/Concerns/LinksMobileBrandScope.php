<?php

namespace Tests\Concerns;

use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Modules\BrandOwner\Models\BrandOwner;

/**
 * Mobile brand isolation fixtures (MobileBranchScopeService).
 *
 * The mobile `brand_owners` table has no brand column: an owner reaches their
 * brand through the identity map → asab_users → asab_user_roles.brand_ids, and
 * branches carry `asab_brand_id`. Mobile branch lists fail closed without those
 * links, so every test that expects a brand owner to SEE branches must build
 * them.
 */
trait LinksMobileBrandScope
{
    /**
     * Link a mobile brand owner to a dashboard brand and tag the branches with it.
     */
    protected function linkBrandOwner(BrandOwner $owner, Branch ...$branches): AsabBrand
    {
        $company = AsabCompany::create(['name' => 'Scope Co '.$owner->id, 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create([
            'company_id' => $company->id,
            'name' => 'براند '.$owner->name,
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $user = AsabUser::create([
            'company_id' => $company->id,
            'name' => $owner->name,
            'email' => $owner->email,
            'password' => 'scope-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'brand-owner',
            'scope' => 'brand',
            'brand_ids' => [$brand->id],
        ]);
        AsabIdentityMap::create([
            'company_id' => $company->id,
            'entity_type' => AsabIdentityMap::ENTITY_BRAND_OWNER,
            'dashboard_type' => 'asab_user',
            'dashboard_id' => $user->id,
            'legacy_type' => 'brand_owner',
            'legacy_id' => $owner->id,
            'match_method' => 'email',
            'linked_email' => $owner->email,
            'source' => 'test',
            'linked_at' => now(),
        ]);

        $this->tagBranchesWithBrand($brand, ...$branches);

        return $brand;
    }

    /** Put more branches under an already-linked brand. */
    protected function tagBranchesWithBrand(AsabBrand $brand, Branch ...$branches): void
    {
        foreach ($branches as $branch) {
            $branch->forceFill([
                'asab_brand_id' => $brand->id,
                'asab_company_id' => $brand->company_id,
            ])->save();
        }
    }
}
