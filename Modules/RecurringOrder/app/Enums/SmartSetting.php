<?php

namespace Modules\RecurringOrder\Enums;

enum SmartSetting: string
{
    case AUTO_ADJUST_QUANTITIES = 'auto_adjust_quantities_based_on_consumption';
    case FREEZE_DURING_HOLIDAYS = 'freeze_during_holidays_and_events';
    case NOTIFY_WHEN_PRICES_CHANGE = 'notify_when_prices_change';

    public function label(): string
    {
        return match ($this) {
            self::AUTO_ADJUST_QUANTITIES => 'Auto-Adjust Quantities Based on Consumption',
            self::FREEZE_DURING_HOLIDAYS => 'Freeze During Holidays and Events',
            self::NOTIFY_WHEN_PRICES_CHANGE => 'Notify When Prices Change',
        };
    }
}
