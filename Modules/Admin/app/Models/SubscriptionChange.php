<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Audit of subscription plan/cycle changes (proration) — COMPANY_DASHBOARD_API_SPEC.md §3.1. */
class SubscriptionChange extends Model
{
    use HasUuids;

    protected $table = 'asab_subscription_changes';

    public $timestamps = false;

    protected $fillable = [
        'subscription_id', 'change_type', 'from_plan_id', 'to_plan_id', 'from_billing_cycle',
        'to_billing_cycle', 'proration_amount', 'effective_at', 'invoice_id', 'initiated_by_id', 'created_at',
    ];

    protected $casts = [
        'proration_amount' => 'integer', 'effective_at' => 'datetime', 'created_at' => 'datetime',
    ];
}
