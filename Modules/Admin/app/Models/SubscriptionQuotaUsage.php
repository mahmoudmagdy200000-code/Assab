<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;

/** Cached quota usage snapshot per company — COMPANY_DASHBOARD_API_SPEC.md §3.1. */
class SubscriptionQuotaUsage extends Model
{
    protected $table = 'asab_subscription_quota_usage';

    protected $primaryKey = 'company_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'used_branches', 'used_users', 'used_brands',
        'used_restaurants', 'used_storage_bytes', 'computed_at',
    ];

    protected $casts = [
        'used_branches' => 'integer', 'used_users' => 'integer', 'used_brands' => 'integer',
        'used_restaurants' => 'integer', 'used_storage_bytes' => 'integer', 'computed_at' => 'datetime',
    ];
}
