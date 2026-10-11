<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;

class ShiftLiabilityPostingState extends Model
{
    use HasUuids;

    protected $table = 'shift_liability_posting_states';

    protected $guarded = ['id'];

    protected $casts = [
        'last_approved_target_halalas' => 'integer',
    ];

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
