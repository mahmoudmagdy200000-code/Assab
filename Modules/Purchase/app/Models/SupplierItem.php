<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Purchase\Models\Item;

class SupplierItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'supplier_id',
        'item_id',
        'unit_price',
        'economy_price',
        'standard_price',
        'premium_price',
        'is_available',
        'min_order_quantity',
        'max_order_quantity',
        'delivery_hours',
        'rating',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'economy_price' => 'decimal:2',
        'standard_price' => 'decimal:2',
        'premium_price' => 'decimal:2',
        'is_available' => 'boolean',
        'min_order_quantity' => 'decimal:3',
        'max_order_quantity' => 'decimal:3',
        'delivery_hours' => 'integer',
        'rating' => 'decimal:2',
    ];

    // Relationships
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\Modules\Supplier\Models\Supplier::class, 'supplier_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    // Scopes
    public function scopeBySupplier($query, string $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeByItem($query, string $itemId)
    {
        return $query->where('item_id', $itemId);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeByDeliveryTime($query, int $maxHours)
    {
        return $query->where('delivery_hours', '<=', $maxHours);
    }

    // Methods
    public function getPriceByQuality(string $quality): float
    {
        return match($quality) {
            'economy' => $this->economy_price ?? $this->unit_price,
            'premium' => $this->premium_price ?? $this->unit_price,
            default => $this->standard_price ?? $this->unit_price,
        };
    }

    public function isWithinQuantityLimits(float $quantity): bool
    {
        if ($this->min_order_quantity && $quantity < $this->min_order_quantity) {
            return false;
        }
        
        if ($this->max_order_quantity && $quantity > $this->max_order_quantity) {
            return false;
        }
        
        return true;
    }
}

