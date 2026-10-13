<?php

namespace Modules\Expense\Services;

use Modules\Admin\Services\MobileBranchScopeService;
use Modules\BrandOwner\Models\BrandOwner;

/**
 * Which brands' expenses a brand owner / brand manager may see and decide (EXP-06 / R-01).
 *
 * The mobile `brand_owners` / `brand_managers` tables carry no brand column and a
 * `BrandManager` inherits from `BrandOwner`, so inheriting the model grants NOTHING:
 * the scope is resolved server-side by the one assignment source the repository has
 * (MobileBranchScopeService: brand owner -> owned brands; brand manager -> explicitly
 * delegated brands). It fails closed — an actor with no resolvable brand gets an empty
 * scope, never "all brands".
 *
 * R-01: the authority is the actor's set of authorised BRAND ids, matched against each
 * expense's IMMUTABLE origin_asab_brand_id. It is deliberately NOT a list of current
 * branch ids: a branch re-tagged to another brand must not carry its historic expenses
 * across, and a manager who moves must not drag their old expenses with them.
 *
 * Nothing the client sends (UUID, brand_id, company_id, branch_manager_id) feeds this.
 */
class ExpenseBrandScopeService
{
    public function __construct(private readonly MobileBranchScopeService $branchScope) {}

    /**
     * Brand ids whose (origin-stamped) expenses this actor may access.
     *
     * @return string[]
     */
    public function brandIdsFor(BrandOwner $actor): array
    {
        return $this->branchScope->visibleBrandIds($actor);
    }
}
