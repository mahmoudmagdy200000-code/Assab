<?php

namespace Modules\Inventory\Enums;

enum WasteDamageReportTimelineEventType: string
{
    case CREATED = 'created';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Report Created',
            self::SUBMITTED => 'Report Submitted',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CREATED => 'plus-circle',
            self::SUBMITTED => 'send',
            self::APPROVED => 'check-circle',
            self::REJECTED => 'x-circle',
        };
    }
}
