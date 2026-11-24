<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryMonthlyCost extends Model
{
    use HasUuids;

    public const STATUS_PROVISIONAL = 'PROVISIONAL';
    public const STATUS_APPROVED = 'APPROVED';

    protected $table = 'inventory_monthly_costs';

    protected $fillable = [
        'company_id',
        'branch_id',
        'item_id',
        'year_month',
        'opening_qty',
        'opening_value_halalas',
        'purchases_qty',
        'purchases_value_halalas',
        'transfers_in_qty',
        'transfers_in_value_halalas',
        'unit_cost_halalas',
        'status',
        'revision',
        'approved_by_id',
        'approved_at',
    ];

    protected $casts = [
        'opening_qty' => 'decimal:3',
        'opening_value_halalas' => 'integer',
        'purchases_qty' => 'decimal:3',
        'purchases_value_halalas' => 'integer',
        'transfers_in_qty' => 'decimal:3',
        'transfers_in_value_halalas' => 'integer',
        'unit_cost_halalas' => 'integer',
        'revision' => 'integer',
        'approved_at' => 'datetime',
    ];
}
