<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'item_name',
        'quantity',
        'unit', // KG, PK, L
        'quality', // economy, standard, premium
        'rate',
        'total_price',
        'status', // pending, confirmed, rejected, modified
        'requested_quantity',
        'confirmed_quantity',
        'received_quantity',
        'variance_quantity',
        'variance_type', // short, damage, over
        'rejection_reason',
        'modification_note',
        'alternative_item_id',
    ];

    protected $casts = [
        'quantity' => 'float',
        'rate' => 'decimal:2',
        'total_price' => 'decimal:2',
        'requested_quantity' => 'float',
        'confirmed_quantity' => 'float',
        'received_quantity' => 'float',
        'variance_quantity' => 'float',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Item::class);
    }

    public function alternativeItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Item::class, 'alternative_item_id');
    }

    // Calculate total price
    public function calculateTotal(): float
    {
        return $this->quantity * $this->rate;
    }

    // Calculate variance amount
    public function calculateVarianceAmount(): float
    {
        return ($this->requested_quantity - $this->received_quantity) * $this->rate;
    }
}
