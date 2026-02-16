<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;

class DailyInventorySchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id',
        'start_date',
        'start_time',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scheduleItems(): HasMany
    {
        return $this->hasMany(DailyInventoryScheduleItem::class, 'daily_inventory_schedule_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }
}
