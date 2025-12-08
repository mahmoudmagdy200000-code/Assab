<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;

class BranchItem extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     */
    protected $table = 'branch_item';
    protected $fillable = [
        'branch_id',
        'item_name',
        'item_logo',
        'item_code',
        'item_unit',
        'item_price',
        'item_quantity',
    ];
    protected $casts = [
        'item_logo' => 'array',
        'item_price' => 'decimal:2',
        'item_quantity' => 'decimal:3',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
