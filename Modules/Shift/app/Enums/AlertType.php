<?php

namespace Modules\Shift\Enums;

enum AlertType: string
{
    case MINOR = 'minor';
    case MAJOR = 'major';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match($this) {
            self::MINOR => 'Minor',
            self::MAJOR => 'Major',
            self::CRITICAL => 'Critical',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::MINOR => 'yellow',
            self::MAJOR => 'orange',
            self::CRITICAL => 'red',
        };
    }

    public static function fromVariance(float $amount, float $percentage): self
    {
        if ($percentage >= 10 || $amount >= 500) {
            return self::CRITICAL;
        } elseif ($percentage >= 5 || $amount >= 200) {
            return self::MAJOR;
        }
        return self::MINOR;
    }
}
