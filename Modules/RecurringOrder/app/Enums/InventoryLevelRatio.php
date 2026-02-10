<?php

namespace Modules\RecurringOrder\Enums;

enum InventoryLevelRatio: string
{
    case EMERGENCY_10 = '10';
    case LOW_20 = '20';
    case MODERATE_40 = '40';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::EMERGENCY_10 => '10% – Emergency',
            self::LOW_20 => '20% – Low',
            self::MODERATE_40 => '40% – Moderate',
            self::CUSTOM => 'Custom',
        };
    }

    public function percentage(): ?int
    {
        return match ($this) {
            self::EMERGENCY_10 => 10,
            self::LOW_20 => 20,
            self::MODERATE_40 => 40,
            self::CUSTOM => null,
        };
    }
}
