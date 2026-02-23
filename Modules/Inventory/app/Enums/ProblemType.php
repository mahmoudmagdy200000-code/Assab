<?php

namespace Modules\Inventory\Enums;

enum ProblemType: string
{
    case WASTE = 'waste';
    case DAMAGE = 'damage';

    public function label(): string
    {
        return match ($this) {
            self::WASTE => 'Waste',
            self::DAMAGE => 'Damage',
        };
    }

    public function isDamage(): bool
    {
        return $this === self::DAMAGE;
    }
}
