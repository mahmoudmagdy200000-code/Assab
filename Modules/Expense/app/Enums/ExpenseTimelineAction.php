<?php

namespace Modules\Expense\Enums;

/**
 * Expense timeline action (expense_timelines.action)
 */
enum ExpenseTimelineAction: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case SUBMIT = 'submit';
    case VIEW = 'view';
    case APPROVE = 'approve';
    case REJECT = 'reject';
    case RESUBMIT = 'resubmit';
    case EDIT = 'edit';
    case ATTACHMENTS_ADDED = 'attachments_added';
    case ATTACHMENTS_DELETED = 'attachments_deleted';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Created',
            self::UPDATED => 'Updated',
            self::SUBMIT => 'Submitted',
            self::VIEW => 'Viewed',
            self::APPROVE => 'Approved',
            self::REJECT => 'Rejected',
            self::RESUBMIT => 'Resubmitted',
            self::EDIT => 'Edited',
            self::ATTACHMENTS_ADDED => 'Attachments Added',
            self::ATTACHMENTS_DELETED => 'Attachments Deleted',
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
