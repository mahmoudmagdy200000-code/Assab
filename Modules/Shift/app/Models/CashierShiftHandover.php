<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Cashier\Models\Cashier;
use Modules\BranchManagers\Models\BranchManager;

class CashierShiftHandover extends Model
{
    use HasUuids;

    protected $table = 'cashier_shift_handovers';

    protected $fillable = [
        'cashier_shift_id',
        'handover_to_id',      // ID of Branch Manager or next Cashier
        'handover_to_type',    // 'branch_manager' or 'cashier'
        'handover_amount',
        'variance_amount',
        'variance_reason',
        'variance_files',
        'handover_notes',
        'handover_date',
        'handover_time',
        'status',              // pending, approved, rejected, rejected_final
        'rejection_reason',
        'rejection_count',
        'first_rejected_at',
        'second_rejected_at',
        'approved_by_id',
        'approved_by_type',
        'approved_at',
        'handed_over_at',
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
    ];

    // Relationships
    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function handoverTo(): MorphTo
    {
        return $this->morphTo();
    }

    public function approvedBy(): MorphTo
    {
        return $this->morphTo('approved_by');
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
        return $this->status === 'rejected_final';
    }

    public function canApprove(): bool
    {
        return $this->isPending();
    }

    public function canReject(): bool
    {
        return $this->isPending() || ($this->status === 'rejected' && $this->rejection_count < 2);
    }
}
