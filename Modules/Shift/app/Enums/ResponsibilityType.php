<?php

namespace Modules\Shift\Enums;

/**
 * Responsibility Type Enum
 */
enum ResponsibilityType: string
{
    case I_WAS_RESPONSIBLE = 'self';
    case ME_AND_OTHER_FACTORS = 'self_and_others';
    case OTHER_FACTORS = 'other_factors';
    case MIXED_FACTORS = 'mixed';

    public function label(): string
    {
        return match($this) {
            self::I_WAS_RESPONSIBLE => 'I Was Responsible (أنا المسؤول)',
            self::ME_AND_OTHER_FACTORS => 'Me and Other Factors (أنا وعوامل أخرى)',
            self::OTHER_FACTORS => 'Other Factors (عوامل أخرى)',
            self::MIXED_FACTORS => 'Mixed Factors (عوامل مختلطة)',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::I_WAS_RESPONSIBLE => 'The cashier accepts full responsibility for the variance',
            self::ME_AND_OTHER_FACTORS => 'The variance is shared between the cashier and other cashiers',
            self::OTHER_FACTORS => 'The variance is due to external factors (not cashier responsibility)',
            self::MIXED_FACTORS => 'The variance is due to multiple factors (cashiers + external)',
        };
    }

    public function requiresOtherCashiers(): bool
    {
        return in_array($this, [
            self::ME_AND_OTHER_FACTORS,
            self::MIXED_FACTORS,
        ]);
    }

    public function requiresReason(): bool
    {
        return in_array($this, [
            self::OTHER_FACTORS,
            self::MIXED_FACTORS,
        ]);
    }

    public function allowsSupportingFiles(): bool
    {
        return in_array($this, [
            self::OTHER_FACTORS,
            self::MIXED_FACTORS,
        ]);
    }
}
