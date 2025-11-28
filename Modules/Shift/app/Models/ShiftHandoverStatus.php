<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Cashier\Models\Cashier;
use Modules\BranchManagers\Models\BranchManager; // تأكد من المسار الصحيح
use Modules\Shift\Enums\HandoverStatus;

class ShiftHandoverStatus extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'shift_handover_status';

    protected $fillable = [
        'cashier_shift_id',
        'status',
        'reviewed_by_id',      // New polymorphic field
        'reviewed_by_type',    // New polymorphic field
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
    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }

    /**
     * Polymorphic relationship for reviewer
     */
    public function reviewedBy(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes and helper methods...
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

    /**
     * Get reviewer name safely
     */
    public function getReviewerNameAttribute(): ?string
    {
        return $this->reviewedBy?->name ?? 'N/A';
    }

    /**
     * Get reviewer type in a readable format
     */
    public function getReviewerTypeAttribute(): ?string
    {
        if (!$this->reviewed_by_type) {
            return null;
        }

        // Convert class name to readable type
        return match ($this->reviewed_by_type) {
            'Modules\BranchManagers\Models\BranchManager' => 'Manager',
            'Modules\Cashier\Models\Cashier' => 'Cashier',
            default => class_basename($this->reviewed_by_type),
        };
    }
}
