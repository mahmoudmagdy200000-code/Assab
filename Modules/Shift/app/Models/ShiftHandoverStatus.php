<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\HandoverStatus;

/**
 * Updated ShiftHandoverStatus Model
 */
class ShiftHandoverStatus extends Model
{
    use HasFactory;

    protected $table = 'shift_handover_status';

    protected $fillable = [
        'cashier_shift_id',
        'status',
        'reviewed_by',
        'rejection_reason',
        'rejection_files',
        'manager_comment',
        'reviewed_at',
    ];

    protected $casts = [
        'status' => HandoverStatus::class,
        'rejection_files' => 'array',
        'reviewed_at' => 'datetime',
    ];

    // Relationships
    public function cashierShift()
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function reviewedBy()
    {
        return $this->belongsTo(Cashier::class, 'reviewed_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', HandoverStatus::PENDING);
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', HandoverStatus::ACCEPTED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', HandoverStatus::REJECTED);
    }

    // Helper Methods
    public function isPending(): bool
    {
        return $this->status === HandoverStatus::PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === HandoverStatus::ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->status === HandoverStatus::REJECTED;
    }

    public function hasRejectionFiles(): bool
    {
        return !empty($this->rejection_files);
    }
}

