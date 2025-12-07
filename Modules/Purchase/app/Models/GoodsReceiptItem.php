<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Purchase\Enums\InspectionQuality;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Enums\VarianceType;

class GoodsReceiptItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_item_id',
        'item_id',
        'item_name',
        'item_logo',
        'unit_of_measurement',
        'quantity_ordered',
        'quantity_received',
        'quantity_variance',
        'quality_ordered',
        'quality_received',
        'has_quality_variance',
        'temperature',
        'expiry_date',
        'photo',
        'notes',
        'unit_price',
        'expected_total',
        'received_total',
        'variance_amount',
        'variance_type',
        'is_unlisted',
        'unlisted_reason',
        'supplier_id',
    ];

    protected $casts = [
        'quality_ordered' => QualityLevel::class,
        'quality_received' => InspectionQuality::class,
        'variance_type' => VarianceType::class,
        'quantity_ordered' => 'decimal:3',
        'quantity_received' => 'decimal:3',
        'quantity_variance' => 'decimal:3',
        'temperature' => 'decimal:2',
        'expiry_date' => 'date',
        'unit_price' => 'decimal:2',
        'expected_total' => 'decimal:2',
        'received_total' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'has_quality_variance' => 'boolean',
        'is_unlisted' => 'boolean',
    ];

    protected $appends = [
        'item_logo_url',
        'photo_url',
        'has_variance',
    ];

    // Relationships
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PurchaseSupplier::class, 'supplier_id');
    }

    public function variance(): HasOne
    {
        return $this->hasOne(PurchaseVariance::class);
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

    public function getPhotoUrlAttribute(): ?string
    {
        if (!$this->photo) {
            return null;
        }
        
        return str_starts_with($this->photo, 'http') 
            ? $this->photo 
            : asset('storage/' . $this->photo);
    }

    public function getHasVarianceAttribute(): bool
    {
        return $this->quantity_variance != 0 || $this->has_quality_variance;
    }

    // Methods
    public function calculateVariance(): void
    {
        $quantityVariance = $this->quantity_ordered - $this->quantity_received;
        $hasQualityVariance = $this->quality_ordered && $this->quality_received && 
                             $this->quality_ordered->value !== $this->quality_received->value;
        
        $expectedTotal = $this->quantity_ordered * $this->unit_price;
        $receivedTotal = $this->quantity_received * $this->unit_price;
        $varianceAmount = $expectedTotal - $receivedTotal;
        
        // Determine variance type
        $varianceType = null;
        if ($quantityVariance > 0 && $hasQualityVariance) {
            $varianceType = VarianceType::BOTH;
        } elseif ($quantityVariance > 0) {
            $varianceType = VarianceType::SHORT;
        } elseif ($hasQualityVariance) {
            $varianceType = VarianceType::DAMAGE;
        }
        
        $this->update([
            'quantity_variance' => $quantityVariance,
            'has_quality_variance' => $hasQualityVariance,
            'expected_total' => $expectedTotal,
            'received_total' => $receivedTotal,
            'variance_amount' => abs($varianceAmount),
            'variance_type' => $varianceType,
        ]);
    }

    public function inspect(array $data): void
    {
        $this->update([
            'quantity_received' => $data['quantity_received'],
            'quality_received' => InspectionQuality::from($data['quality'] ?? 'normal'),
            'temperature' => $data['temperature'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'photo' => $data['photo'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        
        $this->calculateVariance();
    }
}

