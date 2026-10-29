<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASH-14 / BE-13: Two-party cash handover reversal record.
 * Manages the mutual recipient-initiated / sender-confirmed reversal protocol.
 */
class ShiftHandoverReversal extends Model
{
    use HasUuids;

    protected $table = 'shift_handover_reversals';

    protected $fillable = [
        'company_id',
        'branch_id',
        'original_handover_id',
        'original_receipt_id',
        'reversal_reason',
        'initiated_by_user_id',
        'confirmed_by_user_id',
        'confirmed_at',
        'status',
        'corrected_handover_id',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function originalHandover(): BelongsTo
    {
        return $this->belongsTo(CashierShiftHandover::class, 'original_handover_id');
    }

    public function originalReceipt(): BelongsTo
    {
        return $this->belongsTo(CashierShiftHandoverReceipt::class, 'original_receipt_id');
    }

    public function correctedHandover(): BelongsTo
    {
        return $this->belongsTo(CashierShiftHandover::class, 'corrected_handover_id');
    }
}
