<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Procurement item price history point — COMPANY_DASHBOARD_API_SPEC.md §5.5. */
class ProcurementItemPrice extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_procurement_item_prices';

    public $timestamps = false;

    protected $fillable = ['company_id', 'item_id', 'supplier_id', 'supplier_name', 'price', 'recorded_at'];

    protected $casts = ['price' => 'integer', 'recorded_at' => 'datetime'];
}
