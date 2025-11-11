<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptItem extends Model
{
    use HasUuids;
    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_item_id',
        'item_id',
        'item_name',
        'quantity_ordered',
        'quantity_received',
        'unit',
        'quality', // normal, excellent, poor
        'temperature',
        'expiration_date',
        'photo',
        'notes',
        'is_gift', // for unlisted items received as gifts
        'price_per_unit',
        'reason_for_addition',
    ];

    protected $casts = [
        'quantity_ordered' => 'float',
        'quantity_received' => 'float',
        'temperature' => 'float',
        'expiration_date' => 'date',
        'is_gift' => 'boolean',
        'price_per_unit' => 'decimal:2',
    ];

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Item::class);
    }

    // Check if there's a variance
    public function hasVariance(): bool
    {
        return $this->quantity_ordered != $this->quantity_received ||
               $this->quality === 'poor';
    }

    // Calculate variance quantity
    public function getVarianceQuantity(): float
    {
        return $this->quantity_ordered - $this->quantity_received;
    }
}
