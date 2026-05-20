<?php

namespace Modules\Purchase\Enums;

enum ProcessingTime: string
{
    case STANDARD = 'standard';
    case URGENT = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard (3-5 days)',
            self::URGENT => 'Urgent (1-2 days)',
        };
    }

    public function minDays(): int
    {
        return match ($this) {
            self::STANDARD => 3,
            self::URGENT => 1,
        };
    }

    public function maxDays(): int
    {
        return match ($this) {
            self::STANDARD => 5,
            self::URGENT => 2,
        };
    }
}
