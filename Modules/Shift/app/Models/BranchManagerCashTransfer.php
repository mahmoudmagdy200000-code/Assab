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

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'superseded_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

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

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function replacementRequest(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_request_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }
}
