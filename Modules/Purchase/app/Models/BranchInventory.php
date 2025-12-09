<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Enums\QualityLevel;

class BranchInventory extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'branch_inventory';

    protected $fillable = [
        'branch_id',
        'item_id',
        'available_quantity',
        'reserved_quantity',
        'daily_consumption',
        'weekend_forecast',
        'next_supply_date',
        'quality',
        'earliest_expiry_date',
        'cooling_status',
        'last_inventory_update',
    ];

    protected $casts = [
        'available_quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'daily_consumption' => 'decimal:3',
        'weekend_forecast' => 'decimal:3',
        'next_supply_date' => 'date',
        'earliest_expiry_date' => 'date',
        'cooling_status' => 'boolean',
        'last_inventory_update' => 'datetime',
    ];

    protected $appends = [
        'actual_available',
        'is_low_stock',
    ];

    // Relationships
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // Accessors
    public function getActualAvailableAttribute(): float
    {
        return $this->available_quantity - $this->reserved_quantity;
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->actual_available <= ($this->daily_consumption * 2);
    }

    /**
     * Get quality as enum, handling null/empty values safely
     */
    public function getQualityAttribute($value): ?QualityLevel
    {
        if (empty($value) || $value === '') {
            return null;
        }

        try {
            return QualityLevel::from($value);
        } catch (\ValueError $e) {
            return null;
        }
    }

    /**
     * Set quality attribute, handling enum and string values
     */
    public function setQualityAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['quality'] = null;
            return;
        }

        if ($value instanceof QualityLevel) {
            $this->attributes['quality'] = $value->value;
            return;
        }

        try {
            $enum = QualityLevel::from($value);
            $this->attributes['quality'] = $enum->value;
        } catch (\ValueError $e) {
            $this->attributes['quality'] = null;
        }
    }

    // Scopes
    public function scopeByBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeByItem($query, string $itemId)
    {
        return $query->where('item_id', $itemId);
    }

    public function scopeAvailable($query)
    {
        return $query->whereRaw('available_quantity > reserved_quantity');
    }

    public function scopeWithMinAvailability($query, float $percentage, float $requiredQuantity)
    {
        $minQuantity = $requiredQuantity * ($percentage / 100);
        return $query->whereRaw('(available_quantity - reserved_quantity) >= ?', [$minQuantity]);
    }

    // Methods
    public function reserve(float $quantity): bool
    {
        if ($this->actual_available < $quantity) {
            return false;
        }
        
        $this->increment('reserved_quantity', $quantity);
        return true;
    }

    public function releaseReservation(float $quantity): void
    {
        $this->decrement('reserved_quantity', min($quantity, $this->reserved_quantity));
    }

    public function deduct(float $quantity): void
    {
        $this->decrement('available_quantity', $quantity);
        $this->decrement('reserved_quantity', min($quantity, $this->reserved_quantity));
    }

    public function updateInventory(float $quantity, ?string $quality = null): void
    {
        $this->available_quantity = $quantity;
        if ($quality !== null) {
            $this->quality = $quality; // Mutator will handle the conversion
        }
        $this->last_inventory_update = now();
        $this->save();
    }
}

