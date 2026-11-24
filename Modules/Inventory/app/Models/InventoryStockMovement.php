<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryStockMovement extends Model
{
    use HasUuids;

    protected $table = 'inventory_stock_movements';

    protected $fillable = [
        'idempotency_key',
        'company_id',
        'branch_id',
        'item_id',
        'movement_type',
        'qty_delta',
        'unit_cost_halalas',
        'total_cost_halalas',
        'source_type',
        'source_id',
        'operation_id',
        'revision',
        'cost_source',
        'cost_revision',
        'posted_at',
        'created_by_id',
        'notes',
        'meta',
    ];

    protected $casts = [
        'qty_delta' => 'decimal:3',
        'unit_cost_halalas' => 'integer',
        'total_cost_halalas' => 'integer',
        'revision' => 'integer',
        'cost_revision' => 'integer',
        'posted_at' => 'datetime',
        'meta' => 'array',
    ];
}
