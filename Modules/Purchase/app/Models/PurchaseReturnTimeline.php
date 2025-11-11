<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PurchaseReturnTimeline extends Model
{
    use HasUuids;
    protected $fillable = [
        'purchase_return_id',
        'user_id',
        'user_type', // NEW
        'action',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function user(): MorphTo
    {
        return $this->morphTo('user', 'user_type', 'user_id');
    }
}
