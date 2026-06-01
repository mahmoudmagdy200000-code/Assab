<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Company-level subscription (one per company) — COMPANY_DASHBOARD_API_SPEC.md §3.1. */
class CompanySubscription extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_company_subscriptions';

    protected $fillable = [
        'company_id', 'plan_id', 'status', 'billing_cycle', 'current_period_start',
        'current_period_end', 'trial_ends_at', 'cancel_at_period_end', 'cancelled_at',
        'cancellation_reason', 'start_date', 'days_remaining', 'auto_renew',
        'default_payment_method_id', 'contract_number',
    ];

    protected $casts = [
        'current_period_start' => 'datetime', 'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'start_date' => 'datetime',
        'cancel_at_period_end' => 'boolean', 'auto_renew' => 'boolean', 'days_remaining' => 'integer',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function invoices()
    {
        return $this->hasMany(BillingInvoice::class, 'subscription_id');
    }
}
