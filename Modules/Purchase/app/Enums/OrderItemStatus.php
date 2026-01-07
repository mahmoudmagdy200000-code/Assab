<?php

namespace Modules\Purchase\Enums;

enum OrderItemStatus: string
{
    // Decision Phase Statuses
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case REJECTED = 'rejected';
    case NEEDS_APPROVAL = 'needs_approval';
    case NEEDS_APPROVAL_SUPPLIER = 'needs_approval_supplier';
    case NEEDS_APPROVAL_BRANCH = 'needs_approval_branch';

        // Confirmed Statuses (all treated as confirmed)
    case PARTIAL = 'partial';
    case PARTIAL_CONFIRMATION = 'partial_confirmation';
    case CONFIRMED_NEED_TIME = 'confirmed_need_time';
    case CONFIRMED_ALTERNATIVE_PRODUCT = 'confirmed_alternative_product';

        // Execution Phase Statuses
    case PREPARING = 'preparing';
    case ON_THE_WAY = 'on_the_way';
    case DELIVERED = 'delivered';
    
    // Delay Statuses
    case DELAYED_BRANCH = 'delayed_branch';
    case DELAYED_SUPPLIER = 'delayed_supplier';
    case DELAYED_APPROVED = 'delayed_approved';
    
    // Deprecated - use DELAYED_BRANCH or DELAYED_SUPPLIER instead
    case DELAYED = 'delayed';

        // Cancellation Statuses
    case CANCELLED = 'cancelled';
    case CANCELLED_BY_BRANCH = 'cancelled_by_branch';
    case CANCELLED_BY_SUPPLIER = 'cancelled_by_supplier';
    case CANCELED_MODIFICATION = 'cancelled_modification';
    case CANCELLED_DELAYED = 'cancelled_delayed';

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
            self::NEEDS_APPROVAL_SUPPLIER => 'Needs Approval (Supplier)',
            self::NEEDS_APPROVAL_BRANCH => 'Needs Approval (Branch)',
            self::PARTIAL => 'Partial Confirmed',
            self::PARTIAL_CONFIRMATION => 'Partial Confirmation',
            self::CONFIRMED_NEED_TIME => 'Confirmed (Need Time)',
            self::CONFIRMED_ALTERNATIVE_PRODUCT => 'Confirmed (Alternative Product)',
            self::PREPARING => 'Preparing',
            self::ON_THE_WAY => 'On The Way',
            self::DELIVERED => 'Delivered',
            self::DELAYED_BRANCH => 'Delayed (Branch)',
            self::DELAYED_SUPPLIER => 'Delayed (Supplier)',
            self::DELAYED_APPROVED => 'Delayed Approved',
            self::DELAYED => 'Delayed (Deprecated)',
            self::CANCELLED => 'Cancelled',
            self::CANCELLED_BY_BRANCH => 'Cancelled by Branch',
            self::CANCELLED_BY_SUPPLIER => 'Cancelled by Supplier',
            self::CANCELED_MODIFICATION => 'Canceled Modification',
            self::CANCELLED_DELAYED => 'Cancelled (Delayed)',
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
            self::NEEDS_APPROVAL_SUPPLIER => '#F97316',
            self::NEEDS_APPROVAL_BRANCH => '#F97316',
            self::PARTIAL => '#8B5CF6',
            self::PARTIAL_CONFIRMATION => '#8B5CF6',
            self::CONFIRMED_NEED_TIME => '#10B981',
            self::CONFIRMED_ALTERNATIVE_PRODUCT => '#10B981',
            self::PREPARING => '#3B82F6',
            self::ON_THE_WAY => '#6366F1',
            self::DELIVERED => '#22C55E',
            self::DELAYED_BRANCH => '#F59E0B',
            self::DELAYED_SUPPLIER => '#F59E0B',
            self::DELAYED_APPROVED => '#10B981',
            self::DELAYED => '#F59E0B',
            self::CANCELLED => '#EF4444',
            self::CANCELLED_BY_BRANCH => '#EF4444',
            self::CANCELLED_BY_SUPPLIER => '#EF4444',
            self::CANCELED_MODIFICATION => '#EF4444',
            self::CANCELLED_DELAYED => '#EF4444',
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
            self::NEEDS_APPROVAL_SUPPLIER,
            self::NEEDS_APPROVAL_BRANCH,
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
            self::CONFIRMED_NEED_TIME,
            self::CONFIRMED_ALTERNATIVE_PRODUCT,
            self::CANCELLED,
            self::CANCELLED_BY_BRANCH,
            self::CANCELLED_BY_SUPPLIER,
            self::CANCELED_MODIFICATION,
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
     * Check if item status is confirmed (including all confirmed variants)
     *
     * These statuses are treated as confirmed:
     * - confirmed
     * - partial_confirmation
     * - confirmed_need_time
     * - confirmed_alternative_product
     */
    public function isConfirmed(): bool
    {
        return in_array($this, [
            self::CONFIRMED,
            self::PARTIAL_CONFIRMATION,
            self::CONFIRMED_NEED_TIME,
            self::CONFIRMED_ALTERNATIVE_PRODUCT,
        ]);
    }

    /**
     * Check if item is in preparing status
     */
    public function isPreparing(): bool
    {
        return $this === self::PREPARING;
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
            self::CANCELED_MODIFICATION,
            self::CANCELLED_DELAYED,
        ]);
    }
    
    /**
     * Check if item is delayed (any delay status)
     */
    public function isDelayed(): bool
    {
        return in_array($this, [
            self::DELAYED,
            self::DELAYED_BRANCH,
            self::DELAYED_SUPPLIER,
            self::DELAYED_APPROVED,
        ]);
    }

    /**
     * Check if item needs approval (temporary state)
     */
    public function needsApproval(): bool
    {
        return in_array($this, [
            self::NEEDS_APPROVAL,
            self::NEEDS_APPROVAL_SUPPLIER,
            self::NEEDS_APPROVAL_BRANCH,
        ]);
    }

    /**
     * Check if item needs approval from supplier
     */
    public function needsApprovalFromSupplier(): bool
    {
        return $this === self::NEEDS_APPROVAL_SUPPLIER;
    }

    /**
     * Check if item needs approval from branch
     */
    public function needsApprovalFromBranch(): bool
    {
        return $this === self::NEEDS_APPROVAL_BRANCH;
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
