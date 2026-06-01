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

    public function hasTenant(): bool
    {
        return $this->companyId !== null;
    }
}
