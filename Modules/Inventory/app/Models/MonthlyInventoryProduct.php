<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrderItem;

class MonthlyInventoryProduct extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'monthly_inventory_products';

    protected $fillable = [
        'monthly_inventory_id',
        'item_id',
        'purchase_order_item_id',
        'item_name',
        'unit',
        'quantity_inventory',
        'unit_price',
        'category',
        'subcategory',
        'count_method',
        'count_metadata',
        'handled_by_id',
        'handled_by_type',
        'locked_at',
        'branch_id',
        'notes',
    ];

    protected $casts = [
        'quantity_inventory' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'count_metadata' => 'array',
        'locked_at' => 'datetime',
    ];

    public const COUNT_METHOD_SIMPLE = 'simple';
    public const COUNT_METHOD_SLIDER = 'slider';

    public function monthlyInventory(): BelongsTo
    {
        return $this->belongsTo(MonthlyInventory::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function handledBy(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'handled_by_type', 'handled_by_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function getLineValueAttribute(): float
    {
        return (float) $this->quantity_inventory * (float) $this->unit_price;
    }
}
