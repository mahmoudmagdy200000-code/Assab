<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** S1-07 daily-submit lock on a report's liability. Released with a reason on reopen; never deleted. */
class ShiftLiabilityDailyLock extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'allocation_version' => 'integer',
        'locked_at' => 'datetime',
        'released_at' => 'datetime',
        'superseded_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at')->whereNull('superseded_at');
    }
}
