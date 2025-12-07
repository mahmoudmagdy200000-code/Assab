<?php

namespace Modules\Purchase\Enums;

enum Priority: string
{
    case HIGH = 'high';
    case NORMAL = 'normal';

    public function label(): string
    {
        return match($this) {
            self::HIGH => 'High Priority',
            self::NORMAL => 'Normal Priority',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::HIGH => '#EF4444',
            self::NORMAL => '#3B82F6',
        };
    }
}

