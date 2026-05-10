<?php

namespace Modules\FixedAssets\Enums;

enum QuickSearchKey: string
{
    case UPDATE_TODAY = 'update_today';
    case UPDATE_YESTERDAY = 'update_yesterday';
    case NOT_UPDATE_1_MONTH = 'not_update_1_month';
    case NOT_UPDATE_2_MONTH = 'not_update_2_month';
    case IN_MY_CUSTODY = 'in_my_custody';
    case UNDER_MAINTENANCE = 'under_maintenance';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
