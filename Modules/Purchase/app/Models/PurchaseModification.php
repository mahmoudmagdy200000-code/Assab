<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PurchaseModification extends Model
{
    use HasUuids;
    protected $fillable = [
        'purchase_order_id',
        'purchase_order_item_id',
        'modified_by_id',
        'modified_by_type', // NEW
        'modification_type',
        'original_value',
        'new_value',
        'note',
        'status',
        'approved_by_id',
        'approved_by_type', // NEW
        'approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'original_value' => 'array',
        'new_value' => 'array',
        'approved_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function modifiedBy(): MorphTo
    {
        return $this->morphTo('modified_by', 'modified_by_type', 'modified_by_id');
    }

    public function approvedBy(): MorphTo
    {
        return $this->morphTo('approved_by', 'approved_by_type', 'approved_by_id');
    }
}
