<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Cashier\Models\Cashier;

class ShiftVarianceDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'cashier_shift_id',
        'variance_amount',
        'variance_type',
        'responsibility_type',
        'responsible_cashier_id',
        'assigned_amount',
        'reason',
        'supporting_files',
    ];

    protected $casts = [
        'variance_amount' => 'decimal:2',
        'assigned_amount' => 'decimal:2',
        'supporting_files' => 'array',
    ];

    public function cashierShift()
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function responsibleCashier()
    {
        return $this->belongsTo(Cashier::class, 'responsible_cashier_id');
    }

    public function isOver()
    {
        return $this->variance_type === 'over';
    }

    public function isShort()
    {
        return $this->variance_type === 'short';
    }
}
