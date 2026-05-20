<?php

namespace Modules\Purchase\Enums;

enum ReturnRequiredAction: string
{
    case REPLACEMENT = 'replacement';
    case CASH_REFUND = 'cash_refund';
    case CREDIT_FUTURE_ORDER = 'credit_future_order';

    public function label(): string
    {
        return match ($this) {
            self::REPLACEMENT => 'Replacement with Good Product',
            self::CASH_REFUND => 'Cash Refund',
            self::CREDIT_FUTURE_ORDER => 'Credit for Future Order',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::REPLACEMENT => 'Replacement',
            self::CASH_REFUND => 'Refund',
            self::CREDIT_FUTURE_ORDER => 'Credit',
        };
    }
}
