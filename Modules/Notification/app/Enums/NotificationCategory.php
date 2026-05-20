<?php

namespace Modules\Notification\Enums;

enum NotificationCategory: string
{
    case OPERATIONAL = 'operational';
    case FINANCIAL = 'financial';
    case COMPLIANCE = 'compliance';
    case SYSTEM = 'system';

    public function label(): string
    {
        return match ($this) {
            self::OPERATIONAL => 'Operational',
            self::FINANCIAL => 'Financial',
            self::COMPLIANCE => 'Compliance',
            self::SYSTEM => 'System',
        };
    }
}
