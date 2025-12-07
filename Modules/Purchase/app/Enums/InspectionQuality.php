<?php

namespace Modules\Purchase\Enums;

enum InspectionQuality: string
{
    case EXCELLENT = 'excellent';
    case NORMAL = 'normal';
    case POOR = 'poor';

    public function label(): string
    {
        return match($this) {
            self::EXCELLENT => 'Excellent',
            self::NORMAL => 'Normal',
            self::POOR => 'Poor',
        };
    }

    public function score(): int
    {
        return match($this) {
            self::EXCELLENT => 100,
            self::NORMAL => 70,
            self::POOR => 30,
        };
    }

    public function color(): string
    {
        return match($this) {
            self::EXCELLENT => '#22C55E',
            self::NORMAL => '#F59E0B',
            self::POOR => '#EF4444',
        };
    }
}

