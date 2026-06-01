<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Stored payment method (PSP token, never raw PAN) — COMPANY_DASHBOARD_API_SPEC.md §3.2. */
class PaymentMethod extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'asab_payment_methods';

    protected $fillable = [
        'company_id', 'type', 'brand', 'last4', 'exp_month', 'exp_year', 'holder_name',
        'provider_token', 'provider_name', 'is_default', 'status', 'added_by_id',
    ];

    protected $hidden = ['provider_token'];

    protected $casts = [
        'exp_month' => 'integer', 'exp_year' => 'integer', 'is_default' => 'boolean',
    ];
}
