<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Purchase\Enums\InspectionQuality;

class ReturnOrderItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'return_order_id',
        'purchase_order_item_id',
        'item_name',
        'item_logo',
        'return_quantity',
        'unit_of_measurement',
        'quality_reason',
        'unit_price',
        'return_amount',
        'files',
        'notes',
    ];

    protected $casts = [
        'quality_reason' => InspectionQuality::class,
        'return_quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'return_amount' => 'decimal:2',
        'files' => 'array',
    ];

    protected $appends = [
        'item_logo_url',
        'quality_reason_label',
    ];

    // Relationships
    public function returnOrder(): BelongsTo
    {
        return $this->belongsTo(ReturnOrder::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    // Accessors
    public function getItemLogoUrlAttribute(): ?string
    {
        if (! $this->item_logo) {
            return null;
        }

        return str_starts_with($this->item_logo, 'http')
            ? $this->item_logo
            : asset('storage/'.$this->item_logo);
    }

    public function getQualityReasonLabelAttribute(): ?string
    {
        return $this->quality_reason?->label();
    }

    // Methods
    public function calculateReturnAmount(): float
    {
        $this->return_amount = $this->return_quantity * $this->unit_price;
        $this->save();

        return $this->return_amount;
    }
}
