<?php

namespace Modules\Expense\Enums;

/**
 * Expense timeline status (expense_timelines.status) - display state in timeline
 */
enum ExpenseTimelineStatus: string
{
    case SAVED_AS_DRAFT = 'saved_as_draft';
    case SUBMITTED = 'submitted';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case RESUBMITTED = 'resubmitted';
    case VIEWED = 'viewed';
    case CREATED = 'created';
    case UPDATED = 'updated';

    public function label(): string
    {
        return match ($this) {
            self::SAVED_AS_DRAFT => 'Saved as draft',
            self::SUBMITTED => 'Submitted',
            self::PENDING => 'Pending',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::RESUBMITTED => 'Resubmitted',
            self::VIEWED => 'Viewed',
            self::CREATED => 'Created',
            self::UPDATED => 'Updated',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function forApi(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[] = ['value' => $case->value, 'label' => $case->label()];
        }
        return $out;
    }
}
