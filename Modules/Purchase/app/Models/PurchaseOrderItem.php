<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Purchase\Enums\InspectionQuality;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\QualityLevel;

class PurchaseOrderItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'item_name',
        'item_logo',
        'item_sku',
        'category',
        'subcategory',
        'quantity_ordered',
        'quantity_confirmed',
        'quantity_received',
        'unit_of_measurement',
        'unit_price',
        'total_price',
        'discount',
        'quality_ordered',
        'quality_received',
        'available_in_source',
        'remaining_balance',
        'daily_consumption',
        'weekend_forecast',
        'next_supply_date',
        'expiry_date',
        'temperature',
        'cooling_status',
        'inspection_photo',
        'inspection_notes',
        'status',
        'original_quantity',
        'new_quantity',
        'modification_note',
        'is_alternative',
        'original_item_id',
        'is_gift',
        'gift_reason',
    ];

    protected $casts = [
        'status' => OrderItemStatus::class,
        'quality_ordered' => QualityLevel::class,
        'quality_received' => InspectionQuality::class,
        'quantity_ordered' => 'decimal:3',
        'quantity_confirmed' => 'decimal:3',
        'quantity_received' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'available_in_source' => 'decimal:3',
        'remaining_balance' => 'decimal:3',
        'daily_consumption' => 'decimal:3',
        'weekend_forecast' => 'decimal:3',
        'next_supply_date' => 'date',
        'expiry_date' => 'date',
        'temperature' => 'decimal:2',
        'cooling_status' => 'boolean',
        'original_quantity' => 'decimal:3',
        'new_quantity' => 'decimal:3',
        'is_alternative' => 'boolean',
        'is_gift' => 'boolean',
    ];

    protected $appends = [
        'item_logo_url',
        'quantity_variance',
        'has_variance',
    ];

    // Relationships
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function originalItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'original_item_id');
    }

    public function alternatives(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'original_item_id');
    }

    public function goodsReceiptItems(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function returnOrderItems(): HasMany
    {
        return $this->hasMany(ReturnOrderItem::class);
    }

    // Accessors
    public function getItemLogoUrlAttribute(): ?string
    {
        if (!$this->item_logo) {
            return null;
        }
        
        return str_starts_with($this->item_logo, 'http') 
            ? $this->item_logo 
            : asset('storage/' . $this->item_logo);
    }

    public function getQuantityVarianceAttribute(): float
    {
        if ($this->quantity_received === null) {
            return 0;
        }
        
        return $this->quantity_ordered - $this->quantity_received;
    }

    public function getHasVarianceAttribute(): bool
    {
        return $this->quantity_variance != 0 || 
               ($this->quality_ordered && $this->quality_received && 
                $this->quality_ordered->value !== $this->quality_received->value);
    }

    // Scopes
    public function scopeByOrder($query, string $orderId)
    {
        return $query->where('purchase_order_id', $orderId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeReceived($query)
    {
        return $query->where('status', 'received');
    }

    public function scopeWithVariance($query)
    {
        return $query->where('status', 'variance');
    }

    public function scopeGifts($query)
    {
        return $query->where('is_gift', true);
    }

    public function scopeAlternatives($query)
    {
        return $query->where('is_alternative', true);
    }

    // Methods
    public function calculateTotalPrice(): float
    {
        $quantity = $this->quantity_confirmed ?? $this->quantity_ordered;
        $this->total_price = ($quantity * $this->unit_price) - ($this->discount ?? 0);
        $this->save();
        
        return $this->total_price;
    }

    public function confirm(?float $quantity = null): void
    {
        $this->quantity_confirmed = $quantity ?? $this->quantity_ordered;
        $this->status = 'confirmed';
        $this->calculateTotalPrice();
        $this->save();
    }

    public function markAsReceived(float $quantity, ?string $quality = null): void
    {
        $this->quantity_received = $quantity;
        if ($quality) {
            $this->quality_received = InspectionQuality::from($quality);
        }
        $this->status = $this->hasVariance ? 'variance' : 'received';
        $this->save();
    }

    public function updateQuantity(float $newQuantity, ?string $note = null): void
    {
        $this->original_quantity = $this->quantity_ordered;
        $this->new_quantity = $newQuantity;
        $this->quantity_ordered = $newQuantity;
        $this->modification_note = $note;
        $this->calculateTotalPrice();
        $this->save();
    }

    public function calculateBalanceQuantity(): float
    {
        return $this->quantity_ordered - ($this->new_quantity ?? $this->quantity_ordered);
    }
}

