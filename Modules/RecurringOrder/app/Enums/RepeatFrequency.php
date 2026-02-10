<?php

namespace Modules\RecurringOrder\Enums;

enum RepeatFrequency: string
{
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case BASED_ON_INVENTORY = 'based_on_inventory';

    public function label(): string
    {
        return match ($this) {
            self::WEEKLY => 'Weekly',
            self::MONTHLY => 'Monthly',
            self::BASED_ON_INVENTORY => 'Based on Inventory',
        };
    }
}
