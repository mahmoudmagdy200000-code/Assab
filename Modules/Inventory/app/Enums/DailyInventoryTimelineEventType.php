<?php

namespace Modules\Inventory\Enums;

enum DailyInventoryTimelineEventType: string
{
    case CREATED = 'created';
    case SUBMITTED = 'submitted';
    case VIEWED_BY_ACCOUNT_MANAGER = 'viewed_by_account_manager';
    case REJECTED = 'rejected';
    case APPROVED = 'approved';
    case RESUBMITTED = 'resubmitted';
    case DISCREPANCY_REPORT_SHARED = 'discrepancy_report_shared';
    case DISCREPANCY_REVIEWED = 'discrepancy_reviewed';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Created',
            self::SUBMITTED => 'Submitted',
            self::VIEWED_BY_ACCOUNT_MANAGER => 'Viewed by Account Manager',
            self::REJECTED => 'Rejected',
            self::APPROVED => 'Approved',
            self::RESUBMITTED => 'Resubmitted',
            self::DISCREPANCY_REPORT_SHARED => 'Request Received',
            self::DISCREPANCY_REVIEWED => 'Reviewed',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CREATED => 'plus-circle',
            self::SUBMITTED => 'send',
            self::VIEWED_BY_ACCOUNT_MANAGER => 'eye',
            self::REJECTED => 'x-circle',
            self::APPROVED => 'check-circle',
            self::RESUBMITTED => 'rotate-ccw',
            self::DISCREPANCY_REPORT_SHARED => 'file-text',
            self::DISCREPANCY_REVIEWED => 'eye',
        };
    }
}
