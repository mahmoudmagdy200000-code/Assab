<?php

namespace Modules\Inventory\Enums;

enum MonthlyInventoryStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case DRAFT = 'draft';
    case COMPLETED = 'completed';
    case PENDING_YOUR_CONFIRMATION = 'pending_your_confirmation';
    case SUBMITTED = 'submitted';
    case PENDING_FINANCE_REVIEW = 'pending_finance_review';
    case APPROVED = 'approved';
    case RETURNED_TO_DRAFT = 'returned_to_draft';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::IN_PROGRESS => 'In Progress',
            self::DRAFT => 'Draft',
            self::COMPLETED => 'Completed',
            self::PENDING_YOUR_CONFIRMATION => 'Pending Your Confirmation',
            self::SUBMITTED => 'Submitted',
            self::PENDING_FINANCE_REVIEW => 'Pending Finance Review',
            self::APPROVED => 'Approved',
            self::RETURNED_TO_DRAFT => 'Returned to Draft',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => '#F59E0B',
            self::IN_PROGRESS => '#3B82F6',
            self::DRAFT => '#8B5CF6',
            self::COMPLETED => '#10B981',
            self::PENDING_YOUR_CONFIRMATION => '#F59E0B',
            self::SUBMITTED => '#6366F1',
            self::PENDING_FINANCE_REVIEW => '#F59E0B',
            self::APPROVED => '#10B981',
            self::RETURNED_TO_DRAFT => '#EF4444',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::IN_PROGRESS,
            self::DRAFT,
            self::RETURNED_TO_DRAFT,
        ], true);
    }

    public function canSubmit(): bool
    {
        return in_array($this, [self::PENDING, self::IN_PROGRESS, self::COMPLETED, self::RETURNED_TO_DRAFT], true);
    }
}
