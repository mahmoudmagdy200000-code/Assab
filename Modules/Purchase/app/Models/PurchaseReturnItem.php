<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnItem extends Model
{
    protected $fillable = [
        'purchase_return_id',
        'item_id',
        'item_name',
        'return_quantity',
        'unit',
        'quality_reason', // excellent, normal, poor
        'return_amount',
        'files', // json array
        'notes',
    ];

    protected $casts = [
        'return_quantity' => 'float',
        'return_amount' => 'decimal:2',
        'files' => 'array',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Item::class);
    }
}
