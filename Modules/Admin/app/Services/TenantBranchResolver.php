<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabRestaurant;
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
            // Without this a platform account and a plain companyless one share
            // a key (both have no companyId) and inherit each other's answer.
            $ctx->isPlatform ? 'platform' : 'tenant',
            implode(',', $ctx->companyIds()) ?: '-',
            $ctx->scope,
            implode(',', $ctx->branchIds),
            implode(',', $ctx->restaurantIds),
            implode(',', $ctx->brandIds),
        ]);
    }

    /** @return string[]|null */
    private function resolve(TenantContext $ctx): ?array
    {
        // A platform account (supplier / procurement manager) serves every
        // company, so it is unrestricted like the admin. Its controllers narrow
        // by ownership — the supplier portal by payload->supplierId — rather
        // than by branch.
        if ($ctx->isAdmin || $ctx->isPlatform) {
            return null;
        }
        if (! $ctx->hasTenant()) {
            return [];
        }

        // whereIn, not where: an accountant's brands may each carry their own
        // company, and their branches are still theirs (TenantContext::$companyIds).
        $q = Branch::query()->select('id')->whereIn('asab_company_id', $ctx->companyIds());

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

                    // A branch tagged to the brand only through its RESTAURANT
                    // carries a NULL `asab_brand_id` — the common production
                    // shape — so matching that column alone answered «no
                    // branches» and every screen of a brand-scoped accountant
                    // came back empty (2026-08-03 rule; hit again on the
                    // expenses filters, 2026-08-14).
                    $restaurantsOfBrands = AsabRestaurant::withoutGlobalScopes()
                        ->whereIn('brand_id', $ctx->brandIds)->pluck('id')->all();
                    if ($restaurantsOfBrands !== []) {
                        $w->orWhereIn('asab_restaurant_id', $restaurantsOfBrands);
                    }

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
