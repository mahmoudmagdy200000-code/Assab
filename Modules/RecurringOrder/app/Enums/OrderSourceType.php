<?php

namespace Modules\RecurringOrder\Enums;

enum OrderSourceType: string
{
    case DIRECT_SUPPLIER = 'direct_supplier';
    case VIA_PURCHASING_OFFICER = 'via_purchasing_officer';

    public function label(): string
    {
        return match ($this) {
            self::DIRECT_SUPPLIER => 'Via Direct Supplier',
            self::VIA_PURCHASING_OFFICER => 'Via Purchasing Officer',
        };
    }
}
