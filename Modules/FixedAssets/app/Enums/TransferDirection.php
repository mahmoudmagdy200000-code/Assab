<?php

namespace Modules\FixedAssets\Enums;

enum TransferDirection: string
{
    case FROM_BRANCH = 'from_branch';
    case FROM_FINANCE = 'from_finance';
    case TO_BRANCH = 'to_branch';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
