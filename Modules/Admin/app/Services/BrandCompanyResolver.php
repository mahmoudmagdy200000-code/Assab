<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabCompany;

/**
 * Resolves the company a new brand attaches to (admin "Add Brand").
 *
 * companyId is optional on that route: the platform admin is company-less by
 * design, so BelongsToTenant's creating() hook has no TenantContext to stamp
 * company_id from and it cannot be derived from the caller. When the admin
 * omits it, the brand gets a company of its own named after it. Kept out of the
 * controller so the HTTP layer stays thin (SRP); runs inside the caller's DB
 * transaction.
 */
class BrandCompanyResolver
{
    /**
     * Starting tier for an auto-created company, mirroring CompanyController@store.
     * Its max_branches/max_users come from the asab_companies column defaults,
     * which already encode exactly this tier's limits.
     */
    private const DEFAULT_PLAN = 'Basic';

    /** @return string the id of the company the brand belongs to */
    public function resolveFor(?string $companyId, string $brandName): string
    {
        if ($companyId !== null) {
            return $companyId;
        }

        return AsabCompany::create([
            'name' => $brandName,
            'plan' => self::DEFAULT_PLAN,
            'status' => 'trial',
            'modules' => [],
            'start_date' => now(),
            'next_billing' => now()->addMonth(),
        ])->id;
    }
}
