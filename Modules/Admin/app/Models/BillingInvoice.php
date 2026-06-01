<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Subscription invoice ASAB issues to the company — COMPANY_DASHBOARD_API_SPEC.md §3.2. */
class BillingInvoice extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_billing_invoices';

    protected $fillable = [
        'company_id', 'subscription_id', 'public_id', 'issue_date', 'due_date', 'period_start',
        'period_end', 'subtotal', 'vat_rate', 'vat_amount', 'discount', 'total', 'amount_paid',
        'amount_due', 'status', 'payment_method_id', 'paid_at', 'pdf_storage_key',
        'billing_address_snapshot', 'notes', 'currency',
    ];

    protected $casts = [
        'issue_date' => 'datetime', 'due_date' => 'datetime', 'period_start' => 'datetime',
        'period_end' => 'datetime', 'paid_at' => 'datetime', 'subtotal' => 'integer',
        'vat_rate' => 'integer', 'vat_amount' => 'integer', 'discount' => 'integer', 'total' => 'integer',
        'amount_paid' => 'integer', 'amount_due' => 'integer', 'billing_address_snapshot' => 'array',
    ];

    public function lines()
    {
        return $this->hasMany(BillingInvoiceLine::class, 'invoice_id')->orderBy('sort_order');
    }
}
