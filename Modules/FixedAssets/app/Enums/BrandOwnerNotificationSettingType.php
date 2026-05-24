<?php

namespace Modules\FixedAssets\Enums;

enum BrandOwnerNotificationSettingType: string
{
    case AUDIT = 'audit';
    case TRANSFER = 'transfer';
    case STATUS_MODIFICATION = 'status_modification';
    case HANDOVER = 'handover';
    case MAJOR_DISCREPANCIES = 'major_discrepancies';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
