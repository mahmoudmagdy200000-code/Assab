<?php

namespace Modules\Inventory\Enums;

enum WasteDamageReason: string
{
    case EXPIRED = 'expired';
    case DAMAGED = 'damaged';
    case CONTAMINATED = 'contaminated';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::EXPIRED => 'Expired',
            self::DAMAGED => 'Damaged',
            self::CONTAMINATED => 'Contaminated',
            self::OTHER => 'Other',
        };
    }

    /**
     * All values for validation and dropdowns.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
