<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;

class PurchaseTimeline extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'user_id',
        'user_type', // NEW: branch_manager, cashier, etc.
        'action',
        'status',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    // Polymorphic relation
    public function user(): MorphTo
    {
        return $this->morphTo('user', 'user_type', 'user_id');
    }

    // Alternative: Specific relations
    public function branchManager(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'user_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Cashier::class, 'user_id');
    }
}
