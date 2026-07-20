<?php

namespace Modules\Admin\Support;

/**
 * Request-scoped tenant + scope context, populated by ResolveTenant middleware
 * from the authenticated AsabUser. Used by the BelongsToTenant global scope and
 * by repositories to enforce hierarchical (brand/restaurant/branch) isolation.
 */
class TenantContext
{
    public ?string $companyId = null;

    public ?string $roleKey = null;

    /** all|brand|restaurant|branch */
    public string $scope = 'all';

    /** @var string[] */
    public array $brandIds = [];

    /** @var string[] */
    public array $restaurantIds = [];

    /** @var string[] */
    public array $branchIds = [];

    /** @var string[] module keys the user may see */
    public array $moduleKeys = [];

    public bool $isAdmin = false;

    /**
     * Roles that trade with ASAB itself rather than with one company: a supplier
     * and a procurement manager receive orders from EVERY company, so pinning
     * them to one company_id would hide the work they exist to do.
     */
    public const PLATFORM_ROLES = ['supplier', 'procurement'];

    /**
     * True for a platform-level account (a PLATFORM_ROLES role carrying no
     * company). Such a user legitimately reads across companies, so the tenant
     * scope steps aside for them — but ONLY on models that opt in via
     * BelongsToTenant::$platformVisible, and their controllers must then supply
     * the authorization the scope used to. See ResolveTenant.
     */
    public bool $isPlatform = false;

    /**
     * Whether ResolveTenant actually ran. Without it a console command, a queued
     * job and an authenticated-but-companyless request are indistinguishable —
     * all three are "no companyId" — and the global scope has to treat the last
     * one as fail-closed while leaving the first two alone.
     */
    public bool $resolved = false;

    public function hasTenant(): bool
    {
        return $this->companyId !== null;
    }
}
