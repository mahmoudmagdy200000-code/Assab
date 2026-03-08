<?php

namespace Modules\Expense\Enums;

/**
 * Expense payment method (expenses.payment_method)
 */
enum PaymentMethod: string
{
    case CASH = 'cash';
    case SUPPLIER = 'supplier';
    case CUSTODY = 'custody';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash',
            self::SUPPLIER => 'Supplier',
            self::CUSTODY => 'Custody',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function forApi(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[] = ['value' => $case->value, 'label' => $case->label()];
        }
        return $out;
    }
}
