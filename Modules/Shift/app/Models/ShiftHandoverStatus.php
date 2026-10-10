<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\HandoverStatus;

/**
 * ShiftHandoverStatus Model
 *
 * Tracks handover approval workflow under BR-17:
 * - Rejection does not permanently terminate the process; cashier can edit and resubmit
 * - Historical 'rejected_final' records remain editable and correctable
 *
 * @property string $id
 * @property string $cashier_shift_id
 * @property HandoverStatus $status
 * @property string $manager_approval_status
 * @property string|null $reviewed_by_id
 * @property string|null $reviewed_by_type
 * @property string|null $rejection_reason
 * @property array|null $rejection_files
 * @property string|null $manager_comment
 * @property \Carbon\Carbon|null $reviewed_at
 * @property int $rejection_count
 * @property \Carbon\Carbon|null $first_rejected_at
 * @property \Carbon\Carbon|null $second_rejected_at
 * @property bool $was_edited_after_rejection
 * @property \Carbon\Carbon|null $edited_at
 */
class ShiftHandoverStatus extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'shift_handover_status';

    protected $fillable = [
        'cashier_shift_id',
        'status',
        'manager_approval_status',
        'reviewed_by_id',
        'reviewed_by_type',
        'rejection_reason',
        'rejection_files',
        'manager_comment',
        'reviewed_at',
        'rejection_count',
        'first_rejected_at',
        'second_rejected_at',
        'was_edited_after_rejection',
        'edited_at',
    ];

    protected $casts = [
        'status' => HandoverStatus::class,
        'rejection_files' => 'array',
        'reviewed_at' => 'datetime',
        'first_rejected_at' => 'datetime',
        'second_rejected_at' => 'datetime',
        'edited_at' => 'datetime',
        'was_edited_after_rejection' => 'boolean',
        'rejection_count' => 'integer',
    ];

    protected $attributes = [
        'rejection_count' => 0,
        'was_edited_after_rejection' => false,
        'manager_approval_status' => 'pending',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }

    /**
     * Polymorphic relationship for reviewer (BranchManager or Cashier)
     */
    public function reviewedBy(): MorphTo
    {
        return $this->morphTo();
    }

    // ==========================================
    // Scopes
    // ==========================================

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

    public function scopeAwaitingManagerApproval($query)
    {
        return $query->where('manager_approval_status', 'pending');
    }

    public function scopeManagerApproved($query)
    {
        return $query->where('manager_approval_status', 'approved');
    }

    public function scopeManagerRejected($query)
    {
        return $query->whereIn('manager_approval_status', ['rejected', 'rejected_final']);
    }

    public function scopePermanentlyRejected($query)
    {
        return $query->where('manager_approval_status', 'rejected_final');
    }

    // ==========================================
    // Status Check Methods
    // ==========================================

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

    /**
     * Check if handover is awaiting manager approval
     */
    public function isAwaitingManagerApproval(): bool
    {
        return $this->manager_approval_status === 'pending';
    }

    /**
     * Check if manager has approved
     */
    public function isManagerApproved(): bool
    {
        return $this->manager_approval_status === 'approved';
    }

    /**
     * Check if manager has rejected (first or second time)
     */
    public function isManagerRejected(): bool
    {
        return in_array($this->manager_approval_status, ['rejected', 'rejected_final']);
    }

    /**
     * Check if permanently rejected (retired under BR-17; always false).
     */
    public function isPermanentlyRejected(): bool
    {
        return false;
    }

    /**
     * Check if this is the first rejection
     */
    public function isFirstRejection(): bool
    {
        return $this->rejection_count === 1 && $this->manager_approval_status === 'rejected';
    }

    // ==========================================
    // Action Permission Methods
    // ==========================================

    /**
     * Check if handover can be approved by manager
     * Can approve if:
     * - Status is pending
     * - Status is rejected (or legacy rejected_final) and cashier has edited
     */
    public function canBeApproved(): bool
    {
        // Can approve if pending
        if ($this->manager_approval_status === 'pending') {
            return true;
        }

        // Can re-approve after rejection if cashier edited
        if (in_array($this->manager_approval_status, ['rejected', 'rejected_final']) &&
            $this->was_edited_after_rejection) {
            return true;
        }

        return false;
    }

    /**
     * Check if handover can be rejected by manager
     * Business Rule (BR-17): No hard rejection lockout.
     */
    public function canBeRejected(): bool
    {
        // Cannot reject if already approved
        if ($this->isManagerApproved()) {
            return false;
        }

        // Can reject if pending
        if ($this->manager_approval_status === 'pending') {
            return true;
        }

        // Can reject again after rejection if cashier has edited
        if (in_array($this->manager_approval_status, ['rejected', 'rejected_final']) &&
            $this->was_edited_after_rejection) {
            return true;
        }

        return false;
    }

    /**
     * Check if cashier can edit the handover after rejection
     * Business Rule (BR-17): Cashier can edit after rejection, including legacy rejected_final
     */
    public function canCashierEdit(): bool
    {
        return in_array($this->manager_approval_status, ['rejected', 'rejected_final']);
    }

    /**
     * Check if handover can be accepted by next cashier
     */
    public function canBeAcceptedByCashier(): bool
    {
        return $this->isPending() && ! $this->isManagerRejected();
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    public function hasRejectionFiles(): bool
    {
        return ! empty($this->rejection_files);
    }

    /**
     * Get remaining rejection attempts
     */
    public function getRemainingRejectionsAttribute(): int
    {
        return max(0, 2 - $this->rejection_count);
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
        if (! $this->reviewed_by_type) {
            return null;
        }

        return match ($this->reviewed_by_type) {
            'Modules\BranchManagers\Models\BranchManager', 'branch_manager' => 'Branch Manager',
            'Modules\Cashier\Models\Cashier', 'cashier' => 'Cashier',
            default => class_basename($this->reviewed_by_type),
        };
    }

    /**
     * Get a human-readable status label
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->manager_approval_status) {
            'pending' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected (Awaiting Edit)',
            'rejected_final' => 'Rejected (Awaiting Edit)',
            default => 'Unknown',
        };
    }

    /**
     * Get status color for UI
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->manager_approval_status) {
            'pending' => 'yellow',
            'approved' => 'green',
            'rejected' => 'orange',
            'rejected_final' => 'red',
            default => 'gray',
        };
    }

    /**
     * Get rejection files as URLs
     */
    public function getRejectionFileUrlsAttribute(): array
    {
        if (! $this->hasRejectionFiles()) {
            return [];
        }

        return array_map(
            fn ($file) => asset('storage/'.$file),
            $this->rejection_files
        );
    }

    /**
     * Add a rejection file to the existing files
     */
    public function addRejectionFile(string $filePath): void
    {
        $files = $this->rejection_files ?? [];
        $files[] = $filePath;
        $this->rejection_files = $files;
        $this->save();
    }

    /**
     * Remove a rejection file
     */
    public function removeRejectionFile(string $filePath): void
    {
        $files = $this->rejection_files ?? [];
        $files = array_filter($files, fn ($f) => $f !== $filePath);
        $this->rejection_files = array_values($files);
        $this->save();
    }

    // ==========================================
    // Action Methods
    // ==========================================

    /**
     * Mark as approved by manager
     */
    public function approve(string $reviewerId, string $reviewerType, ?string $comment = null): void
    {
        $this->update([
            'status' => HandoverStatus::ACCEPTED,
            'manager_approval_status' => 'approved',
            'reviewed_by_id' => $reviewerId,
            'reviewed_by_type' => $reviewerType,
            'manager_comment' => $comment,
            'reviewed_at' => now(),
            'rejection_count' => 0, // Reset on approval
        ]);
    }

    /**
     * Mark as rejected by manager
     * Handles the 2-rejection business rule
     */
    public function reject(
        string $reviewerId,
        string $reviewerType,
        string $reason,
        array $files = [],
        ?string $comment = null
    ): array {
        $newRejectionCount = $this->rejection_count + 1;

        $updateData = [
            'status' => HandoverStatus::REJECTED,
            'manager_approval_status' => 'rejected',
            'reviewed_by_id' => $reviewerId,
            'reviewed_by_type' => $reviewerType,
            'rejection_reason' => $reason,
            'manager_comment' => $comment,
            'reviewed_at' => now(),
            'rejection_count' => $newRejectionCount,
            'was_edited_after_rejection' => false, // Reset edit flag
        ];

        // Track rejection timestamps
        if ($newRejectionCount === 1) {
            $updateData['first_rejected_at'] = now();
        } elseif ($newRejectionCount === 2) {
            $updateData['second_rejected_at'] = now();
        }

        // Handle rejection files
        if (! empty($files)) {
            $existingFiles = $this->rejection_files ?? [];
            $updateData['rejection_files'] = array_merge($existingFiles, $files);
        }

        $this->update($updateData);

        return [
            'rejection_count' => $newRejectionCount,
            'is_final_rejection' => false,
            'can_cashier_edit' => true,
        ];
    }

    /**
     * Mark as edited by cashier after rejection
     * Resets status to pending for manager re-review
     */
    public function markAsEdited(): void
    {
        $this->update([
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
            'was_edited_after_rejection' => true,
            'edited_at' => now(),
            'rejection_reason' => null, // Clear previous rejection reason
            'manager_comment' => null,
        ]);
    }
}
