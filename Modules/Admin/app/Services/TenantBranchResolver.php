<?php

namespace Modules\Admin\Services;

use Modules\Admin\Support\TenantContext;
use Modules\Branch\Models\Branch;

/**
 * Resolves the branch ids a dashboard user may touch, from their role
 * assignment (asab_user_roles scope + brand/restaurant/branch id arrays).
 * Central place for hierarchical isolation below the company_id global scope:
 * both legacy-domain reads (purchase_orders, branches) and Operation queries
 * use these ids as explicit where-clauses (zero-trust guardrail).
 */
class TenantBranchResolver
{
    /** Memoized per request (registered scoped): the ids cannot change mid-request. */
    private ?array $memo = null;

    private bool $memoSet = false;

    /**
     * Branch ids visible in legacy (mobile-domain) tables.
     * null = unrestricted (platform admin only). Empty array = nothing visible.
     *
     * @return string[]|null
     */
    public function legacyBranchIds(TenantContext $ctx): ?array
    {
        if ($this->memoSet) {
            return $this->memo;
        }

        return $this->memo = $this->resolve($ctx);
    }

    /** @return string[]|null */
    private function resolve(TenantContext $ctx): ?array
    {
        $this->memoSet = true;

        if ($ctx->isAdmin) {
            return null;
        }
        if (! $ctx->hasTenant()) {
            return [];
        }

        $q = Branch::query()->select('id')->where('asab_company_id', $ctx->companyId);

        if ($ctx->scope !== 'all') {
            $q->where(function ($w) use ($ctx) {
                $constrained = false;
                if ($ctx->branchIds !== []) {
                    $w->orWhereIn('id', $ctx->branchIds);
                    $constrained = true;
                }
                if ($ctx->restaurantIds !== []) {
                    $w->orWhereIn('asab_restaurant_id', $ctx->restaurantIds);
                    $constrained = true;
                }
                if ($ctx->brandIds !== []) {
                    $w->orWhereIn('asab_brand_id', $ctx->brandIds);
                    $constrained = true;
                }
                if (! $constrained) {
                    // Scoped assignment with no ids: fail closed.
                    $w->whereRaw('1 = 0');
                }
            });
        }

        return $q->pluck('id')->all();
    }

    /**
     * Extra branch constraint for Operation queries (the company_id global
     * scope already applies). null = no extra constraint (admin or a
     * company-wide assignment).
     *
     * @return string[]|null
     */
    public function operationBranchIds(TenantContext $ctx): ?array
    {
        if ($ctx->isAdmin || $ctx->scope === 'all') {
            return null;
        }

        return $this->legacyBranchIds($ctx);
    }
}
