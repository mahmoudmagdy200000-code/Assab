<?php

namespace Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierProduct extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'item_id',
        'name',
        'description',
        'image',
        'sku',
        'unit_price',
        'economy_price',
        'standard_price',
        'premium_price',
        'is_available',
        'min_order_quantity',
        'max_order_quantity',
        'stock_quantity',
        'delivery_hours',
        'quality_level',
        'rating',
        'specifications',
        'images',
        'categories',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'economy_price' => 'decimal:2',
        'standard_price' => 'decimal:2',
        'premium_price' => 'decimal:2',
        'is_available' => 'boolean',
        'min_order_quantity' => 'decimal:3',
        'max_order_quantity' => 'decimal:3',
        'stock_quantity' => 'decimal:3',
        'delivery_hours' => 'integer',
        'rating' => 'decimal:2',
        'specifications' => 'array',
        'images' => 'array',
        'categories' => 'array',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(SupplierInventory::class, 'product_id');
    }

    public function getPriceByQuality(string $quality): float
    {
        return match ($quality) {
            'economy' => $this->economy_price ?? $this->unit_price,
            'standard' => $this->standard_price ?? $this->unit_price,
            'premium' => $this->premium_price ?? $this->unit_price,
            default => $this->unit_price,
        };
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeByQuality($query, string $quality)
    {
        return $query->where('quality_level', $quality);
    }
}
