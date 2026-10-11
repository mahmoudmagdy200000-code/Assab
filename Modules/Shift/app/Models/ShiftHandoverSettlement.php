<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;

/**
 * S1-11 R6: Immutable settlement evidence for post-receipt adjustments.
 * Never modifies original receipt, but links additive dual counterpart ledger entries.
 */
class ShiftHandoverSettlement extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'shift_handover_settlements';

    protected $guarded = ['id'];

    protected $casts = [
        'signed_delta_halalas' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
        static::updating(fn () => throw new LogicException('Shift handover settlements are immutable.'));
        static::deleting(fn () => throw new LogicException('Shift handover settlements cannot be deleted.'));
    }

    public function originalReceipt(): BelongsTo
    {
        return $this->belongsTo(CashierShiftHandoverReceipt::class, 'original_receipt_id');
    }

    public function originalAttempt(): BelongsTo
    {
        return $this->belongsTo(ShiftTransferAttempt::class, 'original_attempt_id');
    }

    public function sender(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'sender_type', 'sender_id');
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'recipient_type', 'recipient_id');
    }

    public function actor(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'actor_type', 'actor_id');
    }

    public function custodyTransactions(): HasMany
    {
        return $this->hasMany(CashierCustodyTransaction::class, 'settlement_id');
    }

    public function personalLedgerTransactions(): HasMany
    {
        return $this->hasMany(PersonalLedgerTransaction::class, 'settlement_id');
    }
}
