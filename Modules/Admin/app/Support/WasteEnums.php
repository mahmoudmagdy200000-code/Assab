<?php

namespace Modules\Admin\Support;

/**
 * SRS ACC-5 — waste & damage vocabulary. Classification and responsibility are
 * stored as the Arabic strings themselves (no key layer) to match what the
 * mobile app already writes; the constants here are the single source of the
 * allowed values and the ledger category.
 */
final class WasteEnums
{
    public const CLASSIFICATION = ['هدر', 'تالف'];

    public const RESPONSIBILITY = ['موظف', 'مطعم'];

    /** Responsibility that charges the employee ledger. */
    public const RESP_EMPLOYEE = 'موظف';

    public const CATEGORY = 'waste_charge';

    public const CATEGORY_LABEL_AR = 'خصم هدر';

    /** @return array<string, mixed> */
    public static function catalog(): array
    {
        return [
            'classification' => array_map(fn ($v) => ['key' => $v, 'labelAr' => $v], self::CLASSIFICATION),
            'responsibility' => array_map(fn ($v) => ['key' => $v, 'labelAr' => $v], self::RESPONSIBILITY),
        ];
    }
}
