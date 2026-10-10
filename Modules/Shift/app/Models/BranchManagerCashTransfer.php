<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BranchManagerCashTransfer extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['requested_amount' => 'decimal:2'];

    public function sourceWorkday(): BelongsTo
    {
        return $this->belongsTo(BranchManagerShift::class, 'branch_manager_shift_id');
    }

    public function destinationCashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'destination_cashier_shift_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(CashierShiftHandoverReceipt::class, 'branch_manager_cash_transfer_id');
    }
}
