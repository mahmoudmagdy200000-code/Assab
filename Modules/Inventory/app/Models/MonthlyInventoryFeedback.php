<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MonthlyInventoryFeedback extends Model
{
    use HasUuids;

    protected $table = 'monthly_inventory_feedback';

    protected $fillable = [
        'monthly_inventory_id',
        'author_id',
        'author_type',
        'author_name',
        'message',
    ];

    public function monthlyInventory(): BelongsTo
    {
        return $this->belongsTo(MonthlyInventory::class);
    }

    public function author(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'author_type', 'author_id');
    }
}
