<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PurchaseTracking extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'status',
        'driver_name',
        'driver_phone',
        'driver_image',
        'vehicle_number',
        'estimated_arrival',
        'actual_arrival',
        'delivery_address',
        'delivery_notes',
        'quality_certificates',
        'temperature',
        'updated_by_id',
        'updated_by_type', // NEW
    ];

    protected $casts = [
        'quality_certificates' => 'array',
        'estimated_arrival' => 'datetime',
        'actual_arrival' => 'datetime',
        'temperature' => 'float',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function updatedBy(): MorphTo
    {
        return $this->morphTo('updated_by', 'updated_by_type', 'updated_by_id');
    }
}
