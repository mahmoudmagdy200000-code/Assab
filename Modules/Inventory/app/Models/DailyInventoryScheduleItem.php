<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Purchase\Models\Item;

class DailyInventoryScheduleItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'daily_inventory_schedule_id',
        'item_id',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DailyInventorySchedule::class, 'daily_inventory_schedule_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
