<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftLiabilityPosting extends Model
{
    use HasUuids;

    protected $table = 'shift_liability_postings';

    protected $guarded = ['id'];

    protected $casts = [
        'delta_halalas' => 'integer',
        'target_halalas' => 'integer',
        'effective_before_halalas' => 'integer',
    ];

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(ShiftLiabilityAllocation::class, 'allocation_id');
    }
}
