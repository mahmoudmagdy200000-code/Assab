<?php

namespace Modules\Expense\Services;

use Modules\Admin\Services\MobileBranchScopeService;
use Modules\BrandOwner\Models\BrandOwner;

/**
 * Which branches' expenses a brand owner / brand manager may see and decide (EXP-06).
 *
 * The mobile `brand_owners` / `brand_managers` tables carry no brand column and a
 * `BrandManager` inherits from `BrandOwner`, so inheriting the model grants NOTHING:
 * the scope is resolved server-side by the one assignment source the repository has
 * (MobileBranchScopeService: identity map or same e-mail -> asab_users ->
 * asab_user_roles.brand_ids -> branches of those brands). It fails closed — an actor
 * with no resolvable brand gets an empty scope, never "all branches".
 *
 * Nothing the client sends (UUID, brand_id, company_id, branch_manager_id) feeds this.
 */
class ExpenseBrandScopeService
{
    public function __construct(private readonly MobileBranchScopeService $branchScope) {}

    /**
     * Branch ids whose expenses this actor may access.
     *
     * @return string[]
     */
    public function branchIdsFor(BrandOwner $actor): array
    {
        return $this->branchScope->visibleBranchIds($actor);
    }
}
