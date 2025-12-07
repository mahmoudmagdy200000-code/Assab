<?php

namespace Modules\Purchase\Enums;

enum QualityLevel: string
{
    case ECONOMY = 'economy';
    case STANDARD = 'standard';
    case PREMIUM = 'premium';

    public function label(): string
    {
        return match($this) {
            self::ECONOMY => 'Economy',
            self::STANDARD => 'Standard',
            self::PREMIUM => 'Premium',
        };
    }

    public function priceMultiplier(): float
    {
        return match($this) {
            self::ECONOMY => 0.85,
            self::STANDARD => 1.0,
            self::PREMIUM => 1.25,
        };
    }
}

