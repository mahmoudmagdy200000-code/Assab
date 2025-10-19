<?php

namespace Modules\Shift\Enums;

/**
 * Variance Type Enum
 */
enum VarianceType: string
{
    case OVER = 'over';
    case SHORT = 'short';

    public function label(): string
    {
        return match($this) {
            self::OVER => 'Over (زيادة)',
            self::SHORT => 'Short (نقص)',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::OVER => 'green',
            self::SHORT => 'red',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::OVER => '↑',
            self::SHORT => '↓',
        };
    }
}

