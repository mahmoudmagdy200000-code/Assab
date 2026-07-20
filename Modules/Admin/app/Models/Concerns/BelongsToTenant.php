<?php

namespace Modules\Admin\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\Admin\Support\TenantContext;

/**
 * Adds a company_id global scope so non-admin users only ever see their own
 * tenant's rows, and auto-stamps company_id on create. Admin (أمين النظام)
 * bypasses the scope for cross-company platform management.
 *
 * Four cases, and the "no tenant" ones are the subtle half:
 *
 *  - **Admin** — unrestricted, by design.
 *  - **Has a company** — filtered to it, plus the NULL-company platform rows on
 *    models that set `$tenantSharesPlatformRows` (asab_suppliers only).
 *  - **Platform account** (supplier / procurement manager, no company; see
 *    TenantContext::PLATFORM_ROLES) — unrestricted, but ONLY on models that opt
 *    in with `$platformVisible`. Their controllers own the authorization from
 *    that point: the supplier portal filters every order by
 *    `payload->supplierId ∈ own supplier ids`.
 *  - **Authenticated with no company and not one of the above** — fail CLOSED.
 *    This used to apply no WHERE clause at all, so such a user read every
 *    company's rows on every model. ResolveTenant's 403 was the only thing
 *    preventing it, which made relaxing that 403 a cross-tenant data leak
 *    rather than a feature.
 *
 * Outside a request — console commands, queued jobs, the identity-map backfill —
 * `TenantContext::$resolved` is false and no filter applies, unchanged. Failing
 * closed there would silently empty every scheduled report.
 */
trait BelongsToTenant
{
    /**
     * Whether a platform account (supplier / procurement) may read this model
     * across companies. Opt-in per model: the default keeps a platform user
     * fail-closed on everything its portal does not legitimately need.
     *
     * A method rather than a property because a class redeclaring a trait's
     * static property with a different default is a fatal composition error.
     */
    protected static function platformVisible(): bool
    {
        return false;
    }

    /**
     * Whether rows with a NULL company_id are PLATFORM rows that every tenant
     * may read, rather than orphans. Only asab_suppliers works this way: a
     * platform supplier contracts with ASAB and trades with all companies, so
     * hiding it from them would defeat the point.
     *
     * Read-only sharing. Writes still scope by an explicit company_id predicate
     * (ProcurementCompanyController::updateSupplier and friends), so a company
     * can order from a platform supplier but cannot edit or delete one.
     */
    protected static function tenantSharesPlatformRows(): bool
    {
        return false;
    }

    public static function bootBelongsToTenant(): void
    {
        // Resolved per model at boot and captured, so the scope callback does
        // not depend on late static binding surviving into a closure.
        $platformVisible = static::platformVisible();
        $sharesPlatformRows = static::tenantSharesPlatformRows();

        static::addGlobalScope('tenant', function (Builder $builder) use ($platformVisible, $sharesPlatformRows) {
            $ctx = app(TenantContext::class);

            if ($ctx->isAdmin) {
                return;
            }

            if ($ctx->hasTenant()) {
                $column = $builder->getModel()->getTable().'.company_id';

                if ($sharesPlatformRows) {
                    $builder->where(fn ($q) => $q->where($column, $ctx->companyId)->orWhereNull($column));

                    return;
                }

                $builder->where($column, $ctx->companyId);

                return;
            }

            if (! $ctx->resolved) {
                return; // console / queue: no auth context to scope by
            }

            if ($ctx->isPlatform && $platformVisible) {
                return; // cross-company by design; the controller authorizes
            }

            $builder->whereRaw('1 = 0');
        });

        static::creating(function ($model) {
            if (empty($model->company_id)) {
                $ctx = app(TenantContext::class);
                if ($ctx->hasTenant()) {
                    $model->company_id = $ctx->companyId;
                }
            }
        });
    }
}
