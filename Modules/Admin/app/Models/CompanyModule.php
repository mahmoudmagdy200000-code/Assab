<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Per-company module enablement (subset of plan entitlements) — COMPANY_DASHBOARD_API_SPEC.md §3.3. */
class CompanyModule extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_company_modules';

    protected $fillable = [
        'company_id', 'module_key', 'is_active', 'is_in_plan', 'toggled_by_id', 'toggled_at',
    ];

    protected $casts = [
        'is_active' => 'boolean', 'is_in_plan' => 'boolean', 'toggled_at' => 'datetime',
    ];
}
