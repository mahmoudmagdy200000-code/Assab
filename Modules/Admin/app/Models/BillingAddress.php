<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Company billing address (frozen onto invoices at issue) — COMPANY_DASHBOARD_API_SPEC.md §3.2. */
class BillingAddress extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_billing_addresses';

    protected $fillable = [
        'company_id', 'legal_name', 'tax_id', 'cr_number', 'address_line1', 'address_line2',
        'city', 'region', 'postal_code', 'country', 'contact_email', 'contact_phone', 'is_default',
    ];

    protected $casts = ['is_default' => 'boolean'];
}
