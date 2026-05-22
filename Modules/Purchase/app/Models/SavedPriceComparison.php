<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stored snapshot of a price comparison.
 *
 * The `snapshot` column holds the full comparison result produced by
 * PriceComparisonService::comparePrices() at the moment it was saved, so a
 * saved comparison stays stable even when underlying prices change later.
 */
class SavedPriceComparison extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'created_by',
        'item_id',
        'item_name',
        'quantity',
        'snapshot',
        'note',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'snapshot' => 'array',
    ];

    /**
     * Tenant isolation: limit results to a single branch.
     */
    public function scopeForBranch($query, ?string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Filter by item.
     */
    public function scopeByItem($query, string $itemId)
    {
        return $query->where('item_id', $itemId);
    }
}
