<?php

namespace Modules\FixedAssets\Enums;

enum BrandOwnerReportSettingReport: string
{
    case ASSET_STATUS = 'asset_status';
    case BRANCH_PERFORMANCE = 'branch_performance';
    case OVERALL_INVESTMENT = 'overall_investment';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
