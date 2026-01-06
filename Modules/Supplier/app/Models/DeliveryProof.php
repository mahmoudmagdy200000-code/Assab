<?php

namespace Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Purchase\Models\PurchaseOrder;

class DeliveryProof extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'purchase_order_id',
        'recipient_name',
        'recipient_signature',
        'delivery_photos',
        'condition_confirmation',
        'acknowledgment_received_at',
    ];

    protected $casts = [
        'delivery_photos' => 'array',
        'acknowledgment_received_at' => 'datetime',
    ];

    /**
     * Get the purchase order that owns this delivery proof
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}

