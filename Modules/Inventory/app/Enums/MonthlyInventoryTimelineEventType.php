<?php

namespace Modules\Inventory\Enums;

enum MonthlyInventoryTimelineEventType: string
{
    case CREATED = 'created';
    case STARTED = 'started';
    case SAVED = 'saved';
    case REVIEWED = 'reviewed';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case RETURNED_TO_DRAFT = 'returned_to_draft';
    case FEEDBACK_ADDED = 'feedback_added';
    case PRODUCT_COUNT_UPDATED = 'product_count_updated';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Inventory Created',
            self::STARTED => 'Inventory Started',
            self::SAVED => 'Progress Saved',
            self::REVIEWED => 'Marked for Review',
            self::SUBMITTED => 'Submitted for Approval',
            self::APPROVED => 'Approved',
            self::RETURNED_TO_DRAFT => 'Returned to Draft',
            self::FEEDBACK_ADDED => 'Feedback Added',
            self::PRODUCT_COUNT_UPDATED => 'Product Count Updated',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CREATED, self::STARTED => 'plus-circle',
            self::SAVED => 'save',
            self::REVIEWED => 'eye',
            self::SUBMITTED => 'send',
            self::APPROVED => 'check-circle',
            self::RETURNED_TO_DRAFT => 'rotate-ccw',
            self::FEEDBACK_ADDED => 'message-square',
            self::PRODUCT_COUNT_UPDATED => 'edit',
        };
    }
}
