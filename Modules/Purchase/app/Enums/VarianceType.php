<?php

namespace Modules\Purchase\Enums;

enum VarianceType: string
{
    case SHORT = 'short';
    case DAMAGE = 'damage';
    case BOTH = 'both';

    public function label(): string
    {
        return match ($this) {
            self::SHORT => 'Short Quantity',
            self::DAMAGE => 'Damaged Quality',
            self::BOTH => 'Short & Damaged',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SHORT => 'Quantity Ordered – Quantity Received',
            self::DAMAGE => 'Quality Ordered – Quality Received',
            self::BOTH => 'Both quantity shortage and quality damage',
        };
    }
}
