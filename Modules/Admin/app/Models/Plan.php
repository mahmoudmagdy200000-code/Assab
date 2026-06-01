<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Plan catalog (basic|professional|enterprise) — COMPANY_DASHBOARD_API_SPEC.md §3.1. */
class Plan extends Model
{
    use HasUuids;

    protected $table = 'asab_plans';

    protected $fillable = [
        'code', 'name_ar', 'name_en', 'price_monthly', 'price_annual', 'annual_discount_pct',
        'max_branches', 'max_users', 'max_brands', 'max_restaurants', 'storage_gb',
        'modules_included', 'has_account_manager', 'has_advanced_reports', 'has_sla',
        'sla_uptime_pct', 'has_open_api', 'sort_order', 'status',
    ];

    protected $casts = [
        'price_monthly' => 'integer', 'price_annual' => 'integer', 'annual_discount_pct' => 'integer',
        'max_branches' => 'integer', 'max_users' => 'integer', 'max_brands' => 'integer',
        'max_restaurants' => 'integer', 'storage_gb' => 'integer', 'sla_uptime_pct' => 'integer',
        'sort_order' => 'integer', 'modules_included' => 'array',
        'has_account_manager' => 'boolean', 'has_advanced_reports' => 'boolean',
        'has_sla' => 'boolean', 'has_open_api' => 'boolean',
    ];

    public function features()
    {
        return $this->hasMany(PlanFeature::class, 'plan_id')->orderBy('sort_order');
    }
}
