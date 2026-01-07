<?php

namespace Modules\Purchase\Enums;

enum OrderStatus: string
{
    // Decision Phase Statuses
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case REJECTED = 'rejected';
    case CANCELED = 'cancelled';
    case CANCELLED_BY_BRANCH = 'cancelled_by_branch';
    case CANCELLED_BY_SUPPLIER = 'cancelled_by_supplier';
    
    // Execution Phase Statuses
    case PREPARING = 'preparing';
    case ON_THE_WAY = 'on_the_way';
    case DELIVERED = 'delivered';
    case CLOSED = 'closed';
    
    // Special Status (temporary during execution)
    case DELAYED = 'delayed';
    
    // Deprecated Statuses (for backward compatibility - will be migrated)
    case PENDING_CONFIRMATION = 'pending_confirmation';
    case PENDING_APPROVAL = 'pending_approval';
    case PARTIAL_CONFIRMATION = 'partial_confirmation';
    case FULLY_APPROVED = 'fully_approved';
    case PARTIAL_APPROVED = 'partial_approved';
    case PARTIAL_CONFIRMED = 'partial_confirmed';

    public function label(): string
    {
        return match ($this) {
            // Decision Phase
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::CONFIRMED => 'Confirmed',
            self::REJECTED => 'Rejected',
            self::CANCELED => 'Canceled',
            self::CANCELLED_BY_BRANCH => 'Cancelled by Branch',
            self::CANCELLED_BY_SUPPLIER => 'Cancelled by Supplier',
            self::CANCELLED_BY_BRANCH => 'Cancelled by Branch',
            self::CANCELLED_BY_SUPPLIER => 'Cancelled by Supplier',
            
            // Execution Phase
            self::PREPARING => 'Preparing',
            self::ON_THE_WAY => 'On The Way',
            self::DELIVERED => 'Delivered',
            self::CLOSED => 'Closed',
            
            // Special
            self::DELAYED => 'Delay Reported',
            
            // Deprecated (for backward compatibility)
            self::PENDING_CONFIRMATION => 'Pending Confirmation (Deprecated)',
            self::PENDING_APPROVAL => 'Pending Approval (Deprecated)',
            self::PARTIAL_CONFIRMATION => 'Partial Confirmation (Deprecated)',
            self::FULLY_APPROVED => 'Fully Approved (Deprecated)',
            self::PARTIAL_APPROVED => 'Partial Approved (Deprecated)',
            self::PARTIAL_CONFIRMED => 'Partial Confirmed (Deprecated)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            // Decision Phase
            self::DRAFT => '#6B7280',
            self::PENDING => '#F59E0B',
            self::CONFIRMED => '#10B981',
            self::REJECTED => '#DC2626',
            self::CANCELED => '#EF4444',
            self::CANCELLED_BY_BRANCH => '#EF4444',
            self::CANCELLED_BY_SUPPLIER => '#EF4444',
            
            // Execution Phase
            self::PREPARING => '#3B82F6',
            self::ON_THE_WAY => '#06B6D4',
            self::DELIVERED => '#22C55E',
            self::CLOSED => '#6B7280',
            
            // Special
            self::DELAYED => '#F59E0B',
            
            // Deprecated (mapped to similar statuses)
            self::PENDING_CONFIRMATION => '#F97316',
            self::PENDING_APPROVAL => '#F97316',
            self::PARTIAL_CONFIRMATION => '#8B5CF6',
            self::FULLY_APPROVED => '#10B981',
            self::PARTIAL_APPROVED => '#8B5CF6',
            self::PARTIAL_CONFIRMED => '#8B5CF6',
        };
    }

    /**
     * Check if status can transition to another status
     */
    public function canTransitionTo(OrderStatus $newStatus): bool
    {
        $allowedTransitions = match ($this) {
            // Decision Phase Transitions
            self::DRAFT => [self::PENDING, self::CANCELED, self::CANCELLED_BY_BRANCH, self::CANCELLED_BY_SUPPLIER],
            self::PENDING => [self::CONFIRMED, self::REJECTED, self::CANCELED, self::CANCELLED_BY_BRANCH, self::CANCELLED_BY_SUPPLIER],
            self::CONFIRMED => [self::PREPARING, self::CANCELED, self::CANCELLED_BY_BRANCH, self::CANCELLED_BY_SUPPLIER],
            self::REJECTED => [],
            self::CANCELED => [],
            self::CANCELLED_BY_BRANCH => [],
            self::CANCELLED_BY_SUPPLIER => [],
            
            // Execution Phase Transitions
            self::PREPARING => [self::ON_THE_WAY, self::DELAYED, self::CANCELED],
            self::ON_THE_WAY => [self::DELIVERED, self::DELAYED],
            self::DELAYED => [self::PREPARING, self::ON_THE_WAY, self::CANCELED],
            self::DELIVERED => [self::CLOSED],
            self::CLOSED => [],
            
            // Deprecated Statuses (for backward compatibility - allow transitions to new statuses)
            self::PENDING_CONFIRMATION => [self::CONFIRMED, self::REJECTED, self::CANCELED],
            self::PENDING_APPROVAL => [self::CONFIRMED, self::REJECTED, self::CANCELED],
            self::PARTIAL_CONFIRMATION => [self::CONFIRMED, self::REJECTED, self::CANCELED],
            self::FULLY_APPROVED => [self::CONFIRMED, self::PREPARING, self::CANCELED],
            self::PARTIAL_APPROVED => [self::CONFIRMED, self::PREPARING, self::CANCELED],
            self::PARTIAL_CONFIRMED => [self::CONFIRMED, self::PREPARING, self::CANCELED],
        };

        return in_array($newStatus, $allowedTransitions);
    }

    /**
     * Check if order can be received
     */
    public function canReceive(): bool
    {
        return in_array($this, [
            self::CONFIRMED,
            self::PREPARING,
            self::ON_THE_WAY,
            self::DELIVERED,
            // Deprecated (for backward compatibility)
            self::PARTIAL_CONFIRMATION,
            self::FULLY_APPROVED,
            self::PARTIAL_APPROVED,
            self::PARTIAL_CONFIRMED,
        ]);
    }

    /**
     * Check if order is active (not closed/canceled/rejected)
     */
    public function isActive(): bool
    {
        return !in_array($this, [
            self::CLOSED,
            self::CANCELED,
            self::CANCELLED_BY_BRANCH,
            self::CANCELLED_BY_SUPPLIER,
            self::REJECTED,
        ]);
    }

    /**
     * Check if order is in progress
     */
    public function isInProgress(): bool
    {
        return in_array($this, [
            self::CONFIRMED,
            self::PREPARING,
            self::ON_THE_WAY,
            self::DELIVERED,
            // Deprecated (for backward compatibility)
            self::PARTIAL_CONFIRMATION,
            self::FULLY_APPROVED,
            self::PARTIAL_APPROVED,
            self::PARTIAL_CONFIRMED,
        ]);
    }

    /**
     * Get statuses for history (completed orders)
     */
    public static function historyStatuses(): array
    {
        return [
            self::CLOSED,
            self::CANCELED,
            self::CONFIRMED,
            // Deprecated (for backward compatibility)
            self::PARTIAL_CONFIRMATION,
            self::FULLY_APPROVED,
            self::PARTIAL_APPROVED,
            self::PARTIAL_CONFIRMED,
        ];
    }

    /**
     * Get statuses for pending orders (decision phase + execution phase)
     */
    public static function pendingStatuses(): array
    {
        return [
            self::DRAFT,
            self::PENDING,
            self::CONFIRMED,
            self::PREPARING,
            self::ON_THE_WAY,
            self::DELAYED,
            // Deprecated (for backward compatibility)
            self::PENDING_CONFIRMATION,
            self::PENDING_APPROVAL,
            self::PARTIAL_CONFIRMATION,
            self::FULLY_APPROVED,
            self::PARTIAL_APPROVED,
            self::PARTIAL_CONFIRMED,
        ];
    }

    /**
     * Get statuses for goods receiving
     */
    public static function receivingStatuses(): array
    {
        return [
            self::CONFIRMED,
            self::PREPARING,
            self::ON_THE_WAY,
            self::DELIVERED,
            // Deprecated (for backward compatibility)
            self::PARTIAL_CONFIRMATION,
            self::FULLY_APPROVED,
            self::PARTIAL_APPROVED,
            self::PARTIAL_CONFIRMED,
        ];
    }

    /**
     * Check if status is in decision phase
     */
    public function isDecisionPhase(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::PENDING,
            self::CONFIRMED,
            self::REJECTED,
            self::CANCELED,
        ]);
    }

    /**
     * Check if status is in execution phase
     */
    public function isExecutionPhase(): bool
    {
        return in_array($this, [
            self::PREPARING,
            self::ON_THE_WAY,
            self::DELIVERED,
            self::CLOSED,
            self::DELAYED,
        ]);
    }
}
