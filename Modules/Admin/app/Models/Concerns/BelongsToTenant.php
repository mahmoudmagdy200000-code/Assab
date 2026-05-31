<?php

namespace Modules\Admin\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\Admin\Support\TenantContext;

/**
 * Adds a company_id global scope so non-admin users only ever see their own
 * tenant's rows, and auto-stamps company_id on create. Admin (أمين النظام)
 * bypasses the scope for cross-company platform management.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $ctx = app(TenantContext::class);
            if ($ctx->hasTenant() && ! $ctx->isAdmin) {
                $builder->where($builder->getModel()->getTable().'.company_id', $ctx->companyId);
            }
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
