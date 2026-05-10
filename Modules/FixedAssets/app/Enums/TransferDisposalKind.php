<?php

namespace Modules\FixedAssets\Enums;

enum TransferDisposalKind: string
{
    case TRANSFER_TO_BRANCH = 'transfer_to_branch';
    case DISPOSAL = 'disposal';
    case EXTERNAL_TRANSFER = 'external_transfer';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
