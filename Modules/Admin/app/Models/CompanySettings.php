<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Per-company settings (branding, CR/VAT, defaults) — COMPANY_DASHBOARD_API_SPEC.md §3.6. */
class CompanySettings extends Model
{
    use BelongsToTenant;

    protected $table = 'asab_company_settings';

    protected $primaryKey = 'company_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'legal_name', 'display_name', 'logo_emoji', 'logo_url', 'primary_city',
        'cr_number', 'tax_id', 'email', 'phone', 'website', 'address_line', 'default_currency',
        'default_timezone', 'default_language', 'vat_percentage', 'fiscal_year_start',
        'brand_color', 'updated_at', 'updated_by_id',
    ];

    protected $casts = ['vat_percentage' => 'integer', 'updated_at' => 'datetime'];
}
