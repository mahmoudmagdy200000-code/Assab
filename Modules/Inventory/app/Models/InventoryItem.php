<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\InventorySession;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrderItem;

class InventoryItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'inventory_items';

    protected $fillable = [
        'inventory_session_id',
        'purchase_order_item_id',
        'item_id',
        'item_name',
        'quantity_inventory',
        'sales_quantity',
        'recorded_waste',
        'notes',
        'branch_id',
    ];

    protected $casts = [
        'quantity_inventory' => 'decimal:3',
        'sales_quantity' => 'decimal:3',
        'recorded_waste' => 'decimal:3',
    ];

    // Relationships
    public function inventorySession(): BelongsTo
    {
        return $this->belongsTo(InventorySession::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
