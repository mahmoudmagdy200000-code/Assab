<?php

namespace Modules\Purchase\Enums;

enum VarianceAction: string
{
    case ACCEPT = 'accept';
    case COMPENSATORY_ORDER = 'compensatory_order';
    case DEDUCT_FROM_INVOICE = 'deduct_from_invoice';

    public function label(): string
    {
        return match ($this) {
            self::ACCEPT => 'Accept Variance as is',
            self::COMPENSATORY_ORDER => 'Create Compensatory Order',
            self::DEDUCT_FROM_INVOICE => 'Deduct from Invoice Value',
        };
    }

    public function requiresAction(): bool
    {
        return $this !== self::ACCEPT;
    }
}
