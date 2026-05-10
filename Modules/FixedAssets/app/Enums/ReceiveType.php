<?php

namespace Modules\FixedAssets\Enums;

enum ReceiveType: string
{
    case FROM_BRANCH = 'from_branch';
    case FROM_FINANCE = 'from_finance';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
