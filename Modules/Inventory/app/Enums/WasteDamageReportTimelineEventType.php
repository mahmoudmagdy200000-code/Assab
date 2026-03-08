<?php

namespace Modules\Inventory\Enums;

enum WasteDamageReportTimelineEventType: string
{
    case CREATED = 'created';
    case SUBMITTED = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Report Created',
            self::SUBMITTED => 'Report Submitted',
        };
    }
}
