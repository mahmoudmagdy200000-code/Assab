<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Invoice line item — COMPANY_DASHBOARD_API_SPEC.md §3.2. */
class BillingInvoiceLine extends Model
{
    use HasUuids;

    protected $table = 'asab_billing_invoice_lines';

    public $timestamps = false;

    protected $fillable = ['invoice_id', 'description', 'quantity', 'unit_price', 'amount', 'line_type', 'sort_order'];

    protected $casts = [
        'quantity' => 'integer', 'unit_price' => 'integer', 'amount' => 'integer', 'sort_order' => 'integer',
    ];
}
