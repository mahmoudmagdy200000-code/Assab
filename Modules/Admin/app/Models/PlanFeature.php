<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Display features per plan (comparison page) — COMPANY_DASHBOARD_API_SPEC.md §3.1. */
class PlanFeature extends Model
{
    use HasUuids;

    protected $table = 'asab_plan_features';

    public $timestamps = false;

    protected $fillable = ['plan_id', 'label_ar', 'label_en', 'sort_order', 'is_highlighted'];

    protected $casts = ['sort_order' => 'integer', 'is_highlighted' => 'boolean'];
}
