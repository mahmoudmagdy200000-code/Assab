<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\Shift;
use Modules\BranchManagers\Models\BranchManager;


class CashierShift extends Model
{
    use HasFactory;

    protected $table = 'cashier_shifts';

    protected $fillable = [
        'cashier_id',
        'shift_id',
        'shift_date',
        'status',
        'opening_balance',
        'closing_balance',
        'expected_balance',
        'variance',
        'actual_start_time',
        'actual_end_time',
        'next_cashier_id',
        'handed_over_at',
        'handover_notes',
        'original_cashier_id',
        'reassigned_by',
        'reassignment_reason',
        'reassigned_at',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'actual_start_time' => 'datetime',
        'actual_end_time' => 'datetime',
        'handed_over_at' => 'datetime',
        'reassigned_at' => 'datetime',
    ];

    // العلاقات
    public function cashier()
    {
        return $this->belongsTo(Cashier::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function nextCashier()
    {
        return $this->belongsTo(Cashier::class, 'next_cashier_id');
    }

    public function originalCashier()
    {
        return $this->belongsTo(Cashier::class, 'original_cashier_id');
    }

    public function reassignedBy()
    {
        return $this->belongsTo(BranchManager::class, 'reassigned_by');
    }
}
