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
    /**
     * Memoized per resolved context, not per instance. The service is registered
     * scoped, so one HTTP request normally sees one context — but a queue worker
     * or a console command iterating tenants resolves it once and hands it a new
     * context each time. Keying the cache on the context keeps those callers from
     * inheriting the previous tenant's branch list.
     *
     * @var array<string, string[]|null>
     */
    private array $memo = [];

    /**
     * Branch ids visible in legacy (mobile-domain) tables.
     * null = unrestricted (platform admin only). Empty array = nothing visible.
     *
     * @return string[]|null
     */
    public function legacyBranchIds(TenantContext $ctx): ?array
    {
        $key = $this->fingerprint($ctx);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->resolve($ctx);
    }

    /** Everything `resolve()` reads off the context. */
    private function fingerprint(TenantContext $ctx): string
    {
        return implode('|', [
            $ctx->isAdmin ? 'admin' : 'user',
            $ctx->companyId ?? '-',
            $ctx->scope,
            implode(',', $ctx->branchIds),
            implode(',', $ctx->restaurantIds),
            implode(',', $ctx->brandIds),
        ]);
    }

    /** @return string[]|null */
    private function resolve(TenantContext $ctx): ?array
    {
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
