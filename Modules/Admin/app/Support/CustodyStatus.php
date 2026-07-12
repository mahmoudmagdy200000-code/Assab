<?php

namespace Modules\Admin\Support;

/**
 * SRS §7 ACC-8 / §8 HEAD-4 / §4.5 — cash-custody health derived from the
 * remaining balance against the custody's `min_alert` threshold.
 *
 *   remaining < 50,000 halalas (500 SAR)   → critical «حرج»  (near depletion)
 *   remaining < min_alert (default 5,000 SAR) → low «منخفض»
 *   otherwise                                → normal «طبيعي»
 *
 * Legacy rows store `active` in the status column; always derive from the live
 * balance rather than trusting that stored value.
 */
final class CustodyStatus
{
    /** Near-depletion cutoff (ACC-8.1 KPI + critical status). */
    public const NEAR_DEPLETION_HALALAS = 50000; // 500 SAR

    /** Default min-alert threshold when a custody has none (SRS §4.5). */
    public const DEFAULT_MIN_ALERT_HALALAS = 500000; // 5,000 SAR

    public const LABELS = [
        'normal' => 'طبيعي',
        'low' => 'منخفض',
        'critical' => 'حرج',
    ];

    public static function derive(int $remaining, ?int $minAlert): string
    {
        $minAlert = $minAlert ?: self::DEFAULT_MIN_ALERT_HALALAS;

        return match (true) {
            $remaining < self::NEAR_DEPLETION_HALALAS => 'critical',
            $remaining < $minAlert => 'low',
            default => 'normal',
        };
    }

    public static function labelAr(?string $key): string
    {
        return self::LABELS[$key] ?? self::LABELS['normal'];
    }

    public static function isNearDepletion(int $remaining): bool
    {
        return $remaining < self::NEAR_DEPLETION_HALALAS;
    }
}
