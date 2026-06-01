<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Global support-channel config (chat|phone|email) — COMPANY_DASHBOARD_API_SPEC.md §3.5. */
class SupportChannel extends Model
{
    use HasUuids;

    protected $table = 'asab_support_channels';

    public $timestamps = false;

    protected $fillable = [
        'key', 'label_ar', 'label_en', 'value', 'hours_ar', 'hours_en', 'is_available', 'icon', 'sort_order',
    ];

    protected $casts = ['is_available' => 'boolean', 'sort_order' => 'integer'];
}
