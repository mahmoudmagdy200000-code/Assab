<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Cashier\Models\Cashier;

class CashierShiftHandover extends Model
{
    use HasUuids;

    protected $table = 'cashier_shift_handovers';

    protected $fillable = [
        'cashier_shift_id',
        'handover_to_id',
        'handover_to_type',
        'handover_amount',
        'variance_amount',
        'variance_reason',
        'variance_files',
        'handover_notes',
        'handover_date',
        'handover_time',
        'status',
        'rejection_reason',
        'rejection_count',
        'first_rejected_at',
        'second_rejected_at',
        'approved_by_id',
        'approved_by_type',
        'approved_at',
        'handed_over_at',
        'daily_closed_at',
        'report_revision_id',
        'superseded_at',
        'supersedes_id',
        'cancelled_at',
        'cancelled_by_type',
        'cancelled_by_id',
        'cancellation_reason',
        'replacement_request_id',
    ];

    protected $casts = [
        'handover_amount' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'variance_files' => 'array',
        'handover_date' => 'date',
        'handover_time' => 'datetime',
        'first_rejected_at' => 'datetime',
        'second_rejected_at' => 'datetime',
        'approved_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'daily_closed_at' => 'datetime',
        'superseded_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // Relationships
    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function reportRevision(): BelongsTo
    {
        return $this->belongsTo(ShiftReportRevision::class, 'report_revision_id');
    }

    public function receipt(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CashierShiftHandoverReceipt::class, 'cashier_shift_handover_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function replacementRequest(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_request_id');
    }

    /**
     * Polymorphic relationship: who receives the handover
     */
    public function handoverTo(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Polymorphic relationship: who approved the handover
     */
    public function approvedBy(): MorphTo
    {
        return $this->morphTo('approved_by');
    }

    /**
     * Get cashier details through cashier shift
     */
    public function cashier()
    {
        return $this->cashierShift?->cashier;
    }

    /**
     * Get branch manager if handover is to a manager
     */
    public function branchManager()
    {
        if ($this->handover_to_type === 'branch_manager') {
            return $this->handoverTo;
        }

        return null;
    }

    /**
     * The branch manager who actually took custody of the cash: approval is
     * branch-wide, so when a branch manager other than the addressed one
     * approved, the cash physically sits with the approver — custody ledger
     * entries must follow them, not the addressed recipient.
     */
    public function receivingBranchManagerId(): ?string
    {
        if ($this->handover_to_type !== 'branch_manager') {
            return null;
        }

        $approverType = (string) ($this->approved_by_type ?? '');
        $approverIsBranchManager = $this->approved_by_id
            && (str_contains($approverType, 'BranchManager') || $approverType === 'branch_manager');

        return $approverIsBranchManager ? (string) $this->approved_by_id : $this->handover_to_id;
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->whereIn('status', ['rejected', 'rejected_final']);
    }

    public function scopeForManager($query, $managerId)
    {
        return $query->where('handover_to_id', $managerId)
            ->where('handover_to_type', 'branch_manager');
    }

    public function scopeForShiftDate($query, $date)
    {
        return $query->whereHas('cashierShift', function ($q) use ($date) {
            $q->whereDate('shift_date', $date);
        });
    }

    // Helper methods
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return in_array($this->status, ['rejected', 'rejected_final']);
    }

    public function isFinalRejection(): bool
    {
        return false;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function canApprove(): bool
    {
        return $this->isPending() && ! $this->isCancelled() && ! $this->isSuperseded();
    }

    public function canReject(): bool
    {
        if ($this->isCancelled() || $this->isSuperseded()) {
            return false;
        }

        return $this->isPending() || in_array($this->status, ['rejected', 'rejected_final']);
    }

    /**
     * Get variance type label
     */
    public function getVarianceTypeAttribute(): string
    {
        if ($this->variance_amount > 0) {
            return 'Over';
        } elseif ($this->variance_amount < 0) {
            return 'Short';
        }

        return 'None';
    }

    /**
     * Get formatted handover amount
     */
    public function getFormattedHandoverAmountAttribute(): string
    {
        return number_format($this->handover_amount, 2);
    }

    /**
     * Get formatted variance amount
     */
    public function getFormattedVarianceAmountAttribute(): string
    {
        return number_format($this->variance_amount, 2);
    }
}
