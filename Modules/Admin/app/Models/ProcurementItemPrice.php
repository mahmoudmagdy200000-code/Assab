<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Procurement item price history point — COMPANY_DASHBOARD_API_SPEC.md §5.5. */
class ProcurementItemPrice extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * A PLATFORM procurement account keeps its own (companyless) catalog, so it
     * must be able to read the price history of the rows it owns. Fail-closed
     * would answer «لا يوجد سجل أسعار» for every platform item. The controller
     * still narrows to `company_id IS NULL`, so a company's history stays
     * unreadable from a platform account (2026-08-04).
     */
    protected static function platformVisible(): bool
    {
        return true;
    }

    protected $table = 'asab_procurement_item_prices';

    public $timestamps = false;

    protected $fillable = ['company_id', 'item_id', 'supplier_id', 'supplier_name', 'price', 'recorded_at'];

    protected $casts = ['price' => 'integer', 'recorded_at' => 'datetime'];
}
