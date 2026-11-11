<?php

namespace Modules\Shift\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Cashier\Models\Cashier;

class BranchShiftRosterAssignment extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'branch_shift_roster_assignments';

    protected $fillable = [
        'branch_id',
        'shift_id',
        'cashier_id',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Cashier::class, 'cashier_id');
    }

    /**
     * Scope for assignments effective on a given date.
     */
    public function scopeActiveForDate(Builder $query, Carbon|string $date): Builder
    {
        $d = $date instanceof Carbon ? $date->format('Y-m-d') : $date;

        return $query->whereDate('effective_from', '<=', $d)
            ->where(function (Builder $q) use ($d) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $d);
            });
    }
}
