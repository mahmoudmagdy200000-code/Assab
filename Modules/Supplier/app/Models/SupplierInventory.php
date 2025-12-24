<?php

namespace Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInventory extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'supplier_inventory';

    protected $fillable = [
        'supplier_id',
        'product_id',
        'quantity',
        'reserved_quantity',
        'reorder_level',
        'max_stock_level',
        'last_restocked_at',
        'expiry_date',
        'batch_number',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'reorder_level' => 'decimal:3',
        'max_stock_level' => 'decimal:3',
        'last_restocked_at' => 'date',
        'expiry_date' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class, 'product_id');
    }

    public function getAvailableQuantityAttribute(): float
    {
        return max(0, $this->quantity - $this->reserved_quantity);
    }

    public function isLowStock(): bool
    {
        return $this->reorder_level && $this->available_quantity <= $this->reorder_level;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }
}
