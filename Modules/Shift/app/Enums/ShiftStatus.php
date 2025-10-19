<?php

namespace Modules\Shift\Enums;

/**
 * Handover Status Enum
 */
enum HandoverStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::ACCEPTED => 'Accepted',
            self::REJECTED => 'Rejected',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PENDING => 'yellow',
            self::ACCEPTED => 'green',
            self::REJECTED => 'red',
        };
    }
}

/**
 * Variance Type Enum
 */
enum VarianceType: string
{
    case OVER = 'over';
    case SHORT = 'short';

    public function label(): string
    {
        return match($this) {
            self::OVER => 'Over (زيادة)',
            self::SHORT => 'Short (نقص)',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::OVER => 'green',
            self::SHORT => 'red',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::OVER => '↑',
            self::SHORT => '↓',
        };
    }
}

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

/**
 * Alert Type Enum (for Variance Alerts)
 */
enum AlertType: string
{
    case MINOR = 'minor';
    case MAJOR = 'major';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match($this) {
            self::MINOR => 'Minor',
            self::MAJOR => 'Major',
            self::CRITICAL => 'Critical',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::MINOR => 'yellow',
            self::MAJOR => 'orange',
            self::CRITICAL => 'red',
        };
    }

    public static function fromVariance(float $amount, float $percentage): self
    {
        if ($percentage >= 10 || $amount >= 500) {
            return self::CRITICAL;
        } elseif ($percentage >= 5 || $amount >= 200) {
            return self::MAJOR;
        } else {
            return self::MINOR;
        }
    }
}

