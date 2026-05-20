<?php

namespace Modules\Purchase\Enums;

enum ReturnStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case ESCALATED = 'escalated';
    case ESCALATED_RESOLVED = 'escalated_resolved';
    case ESCALATED_REJECTED = 'escalated_rejected';
    case CLOSED = 'closed';
    case RESOLVED = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::ESCALATED => 'Escalated',
            self::ESCALATED_RESOLVED => 'Escalation Resolved',
            self::ESCALATED_REJECTED => 'Escalation Rejected',
            self::CLOSED => 'Closed',
            self::RESOLVED => 'Resolved',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => '#6B7280',
            self::PENDING => '#F59E0B',
            self::APPROVED => '#22C55E',
            self::REJECTED => '#EF4444',
            self::ESCALATED => '#F97316',
            self::ESCALATED_RESOLVED => '#10B981',
            self::ESCALATED_REJECTED => '#EF4444',
            self::CLOSED => '#6B7280',
            self::RESOLVED => '#10B981',
        };
    }

    public function isCompleted(): bool
    {
        return in_array($this, [
            self::CLOSED,
            self::RESOLVED,
            self::ESCALATED_RESOLVED,
            self::ESCALATED_REJECTED,
        ]);
    }

    public function isInProgress(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::APPROVED,
            self::REJECTED,
            self::ESCALATED,
        ]);
    }
}
