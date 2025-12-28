<?php

namespace Modules\Purchase\Enums;

enum OrderItemStatus: string
{
    // Decision Phase Statuses
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case REJECTED = 'rejected';
    case NEEDS_APPROVAL = 'needs_approval';
    
    case PARTIAL = 'partial';
    case PARTIAL_CONFIRMATION = 'partial_confirmation';
    
    // Cancellation Statuses
    case CANCELLED = 'cancelled';
    case CANCELLED_BY_BRANCH = 'cancelled_by_branch';
    case CANCELLED_BY_SUPPLIER = 'cancelled_by_supplier';
    
    // Execution Phase Statuses
    case RECEIVED = 'received';
    case VARIANCE = 'variance';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::CONFIRMED => 'Confirmed',
            self::REJECTED => 'Rejected',
            self::NEEDS_APPROVAL => 'Needs Approval',
            self::PARTIAL => 'Partial Confirmed',
            self::PARTIAL_CONFIRMATION => 'Partial Confirmation',
            self::CANCELLED => 'Cancelled',
            self::CANCELLED_BY_BRANCH => 'Cancelled by Branch',
            self::CANCELLED_BY_SUPPLIER => 'Cancelled by Supplier',
            self::RECEIVED => 'Received',
            self::VARIANCE => 'Variance',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => '#F59E0B',
            self::CONFIRMED => '#10B981',
            self::REJECTED => '#EF4444',
            self::NEEDS_APPROVAL => '#F97316',
            self::PARTIAL => '#8B5CF6',
            self::PARTIAL_CONFIRMATION => '#8B5CF6',
            self::CANCELLED => '#EF4444',
            self::CANCELLED_BY_BRANCH => '#EF4444',
            self::CANCELLED_BY_SUPPLIER => '#EF4444',
            self::RECEIVED => '#22C55E',
            self::VARIANCE => '#F97316',
        };
    }

    /**
     * Check if item is in decision phase (not yet decided)
     */
    public function isPendingDecision(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::NEEDS_APPROVAL,
        ]);
    }

    /**
     * Check if item is decided (confirmed, rejected, or partial)
     */
    public function isDecided(): bool
    {
        return in_array($this, [
            self::CONFIRMED,
            self::REJECTED,
            self::PARTIAL,
            self::PARTIAL_CONFIRMATION,
            self::CANCELLED,
            self::CANCELLED_BY_BRANCH,
            self::CANCELLED_BY_SUPPLIER,
        ]);
    }

    /**
     * Check if item is accepted (for execution phase)
     */
    public function isAccepted(): bool
    {
        return in_array($this, [
            self::CONFIRMED,
            self::RECEIVED,
        ]);
    }

    /**
     * Check if item is rejected
     */
    public function isRejected(): bool
    {
        return $this === self::REJECTED;
    }

    /**
     * Check if item is cancelled
     */
    public function isCancelled(): bool
    {
        return in_array($this, [
            self::CANCELLED,
            self::CANCELLED_BY_BRANCH,
            self::CANCELLED_BY_SUPPLIER,
        ]);
    }

    /**
     * Check if item needs approval (temporary state)
     */
    public function needsApproval(): bool
    {
        return $this === self::NEEDS_APPROVAL;
    }

    /**
     * Check if item is in execution phase
     */
    public function isInExecution(): bool
    {
        return in_array($this, [
            self::RECEIVED,
            self::VARIANCE,
        ]);
    }
}

