<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Authoritative, immutable confirmation fact for one transfer request. */
class CashierShiftHandoverReceipt extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'confirmed_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Confirmed transfer receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Confirmed transfer receipts are immutable.'));
    }

    public function handover(): BelongsTo
    {
        return $this->belongsTo(CashierShiftHandover::class, 'cashier_shift_handover_id');
    }

    public function managerTransfer(): BelongsTo
    {
        return $this->belongsTo(BranchManagerCashTransfer::class, 'branch_manager_cash_transfer_id');
    }

    public function receivingShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'receiving_cashier_shift_id');
    }

    public function reportRevision(): BelongsTo
    {
        return $this->belongsTo(ShiftReportRevision::class, 'report_revision_id');
    }
}
