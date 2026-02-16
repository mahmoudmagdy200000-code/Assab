<?php

namespace Modules\Inventory\Enums;

enum InventorySessionStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case PENDING_YOUR_ACTION = 'pending_your_action';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::PENDING_YOUR_ACTION => 'Pending Your Action',
            self::COMPLETED => 'Completed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => '#F59E0B',
            self::PENDING => '#6366F1',
            self::APPROVED => '#10B981',
            self::REJECTED => '#EF4444',
            self::PENDING_YOUR_ACTION => '#F59E0B',
            self::COMPLETED => '#10B981',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::REJECTED], true);
    }

    public function canResubmit(): bool
    {
        return $this === self::REJECTED;
    }

    public function canSubmit(): bool
    {
        return $this === self::DRAFT;
    }
}

