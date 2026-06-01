<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Payment attempt against an invoice — COMPANY_DASHBOARD_API_SPEC.md §3.2. */
class PaymentTransaction extends Model
{
    use HasUuids;

    protected $table = 'asab_payment_transactions';

    public $timestamps = false;

    protected $fillable = [
        'invoice_id', 'payment_method_id', 'amount', 'currency', 'status', 'provider_txn_id',
        'provider_response', 'failure_code', 'failure_message', 'processed_at', 'created_at',
    ];

    protected $casts = [
        'amount' => 'integer', 'provider_response' => 'array',
        'processed_at' => 'datetime', 'created_at' => 'datetime',
    ];
}
