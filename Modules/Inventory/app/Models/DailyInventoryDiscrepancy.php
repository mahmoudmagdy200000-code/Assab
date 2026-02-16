<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyInventoryDiscrepancy extends Model
{
    use HasUuids;

    protected $fillable = [
        'inventory_session_id',
        'inventory_item_id',
        'item_id',
        'opening_balance',
        'purchases',
        'sales',
        'recorded_waste',
        'net_transfer_in',
        'net_transfer_out',
        'theoretically_expected',
        'actual',
        'difference_quantity',
        'difference_value_sar',
        'discrepancy_type',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:3',
        'purchases' => 'decimal:3',
        'sales' => 'decimal:3',
        'recorded_waste' => 'decimal:3',
        'net_transfer_in' => 'decimal:3',
        'net_transfer_out' => 'decimal:3',
        'theoretically_expected' => 'decimal:3',
        'actual' => 'decimal:3',
        'difference_quantity' => 'decimal:3',
        'difference_value_sar' => 'decimal:2',
    ];

    public function inventorySession(): BelongsTo
    {
        return $this->belongsTo(InventorySession::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
