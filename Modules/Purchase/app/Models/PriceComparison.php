<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\Supplier;

class PriceComparison extends Model
{
    protected $fillable = [
        'item_id',
        'branch_id',
        'order_type', // direct_supplier, purchasing_officer, internal_transfer
        'supplier_id',
        'transfer_from_branch_id',
        'price',
        'delivery_days',
        'rating',
        'recorded_date',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'rating' => 'decimal:1',
        'recorded_date' => 'date',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Item::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function transferFromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'transfer_from_branch_id');
    }
}
