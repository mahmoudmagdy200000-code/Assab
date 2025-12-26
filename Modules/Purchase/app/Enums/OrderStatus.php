<?php

namespace Modules\Purchase\Enums;

enum OrderStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case PENDING_CONFIRMATION = 'pending_confirmation';
    case PENDING_APPROVAL = 'pending_approval';
    case PARTIAL_CONFIRMATION = 'partial_confirmation';
    case CONFIRMED = 'confirmed';
    case PREPARING = 'preparing';
    case ON_THE_WAY = 'on_the_way';
    case DELIVERED = 'delivered';
    case CLOSED = 'closed';
    case CANCELED = 'canceled';
    case REJECTED = 'rejected';
    case DELAYED = 'delayed';
    case FULLY_APPROVED = 'fully_approved';
    case PARTIAL_APPROVED = 'partial_approved';
    case PARTIAL_CONFIRMED = 'partial_confirmed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::PENDING_CONFIRMATION => 'Pending Your Confirmation',
            self::PENDING_APPROVAL => 'Pending Your Approval',
            self::PARTIAL_CONFIRMATION => 'Partial Confirmation',
            self::CONFIRMED => 'Confirmed',
            self::PREPARING => 'Preparing',
            self::ON_THE_WAY => 'On The Way',
            self::DELIVERED => 'Delivered',
            self::CLOSED => 'Closed',
            self::CANCELED => 'Canceled',
            self::REJECTED => 'Rejected',
            self::DELAYED => 'Delay Reported',
            self::FULLY_APPROVED => 'Fully Approved',
            self::PARTIAL_APPROVED => 'Partial Approved',
            self::PARTIAL_CONFIRMED => 'Partial Confirmed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => '#6B7280',
            self::PENDING => '#F59E0B',
            self::PENDING_CONFIRMATION => '#F97316',
            self::PENDING_APPROVAL => '#F97316',
            self::PARTIAL_CONFIRMATION => '#8B5CF6',
            self::CONFIRMED => '#10B981',
            self::PREPARING => '#3B82F6',
            self::ON_THE_WAY => '#06B6D4',
            self::DELIVERED => '#22C55E',
            self::CLOSED => '#6B7280',
            self::CANCELED => '#EF4444',
            self::REJECTED => '#DC2626',
            self::DELAYED => '#F59E0B',
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
            self::DRAFT => [self::PENDING, self::CANCELED],
            self::PENDING => [self::PENDING_CONFIRMATION, self::PENDING_APPROVAL, self::CONFIRMED, self::FULLY_APPROVED, self::PARTIAL_CONFIRMATION, self::PARTIAL_APPROVED, self::REJECTED, self::CANCELED],
            self::PENDING_CONFIRMATION => [self::CONFIRMED, self::FULLY_APPROVED, self::REJECTED, self::CANCELED],
            self::PENDING_APPROVAL => [self::CONFIRMED, self::FULLY_APPROVED, self::PARTIAL_CONFIRMATION, self::PARTIAL_APPROVED, self::REJECTED, self::CANCELED],
            self::PARTIAL_CONFIRMATION => [self::CONFIRMED, self::FULLY_APPROVED, self::PARTIAL_APPROVED, self::PREPARING, self::CANCELED],
            self::CONFIRMED => [self::PREPARING, self::CANCELED],
            self::PREPARING => [self::ON_THE_WAY, self::DELAYED, self::CANCELED],
            self::ON_THE_WAY => [self::DELIVERED, self::DELAYED],
            self::DELAYED => [self::PREPARING, self::ON_THE_WAY, self::CANCELED],
            self::DELIVERED => [self::CLOSED],
            self::CLOSED => [],
            self::CANCELED => [],
            self::REJECTED => [],
            self::FULLY_APPROVED => [self::CONFIRMED, self::PREPARING, self::CANCELED],
            self::PARTIAL_APPROVED => [self::PARTIAL_CONFIRMED, self::FULLY_APPROVED, self::PREPARING, self::CANCELED],
            self::PARTIAL_CONFIRMED => [self::PREPARING, self::CANCELED],
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
            self::PARTIAL_CONFIRMATION,
            self::FULLY_APPROVED,
            self::PARTIAL_APPROVED,
            self::PARTIAL_CONFIRMED,
            self::DELIVERED,
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
            self::REJECTED,
        ]);
    }

    /**
     * Check if order is in progress
     */
    public function isInProgress(): bool
    {
        return in_array($this, [
            self::PREPARING,
            self::ON_THE_WAY,
            self::CONFIRMED,
            self::PARTIAL_CONFIRMATION,
            self::FULLY_APPROVED,
            self::PARTIAL_APPROVED,
            self::PARTIAL_CONFIRMED,
            self::DELIVERED,
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
            self::PARTIAL_CONFIRMATION,
            self::FULLY_APPROVED,
            self::PARTIAL_APPROVED,
            self::PARTIAL_CONFIRMED,
        ];
    }

    /**
     * Get statuses for pending orders
     */
    public static function pendingStatuses(): array
    {
        return [
            self::DRAFT,
            self::PENDING,
            self::PENDING_CONFIRMATION,
            self::PENDING_APPROVAL,
            self::PARTIAL_CONFIRMATION,
            self::CONFIRMED,
            self::FULLY_APPROVED,
            self::PARTIAL_APPROVED,
            self::PARTIAL_CONFIRMED,
            self::PREPARING,
            self::ON_THE_WAY,
            self::DELAYED,
        ];
    }

    /**
     * Get statuses for goods receiving
     */
    public static function receivingStatuses(): array
    {
        return [
            self::PREPARING,
            self::ON_THE_WAY,
            self::PARTIAL_CONFIRMATION,
            self::CONFIRMED,
            self::FULLY_APPROVED,
            self::PARTIAL_APPROVED,
            self::PARTIAL_CONFIRMED,
            self::DELIVERED,
        ];
    }
}
