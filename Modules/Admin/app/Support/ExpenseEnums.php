<?php

namespace Modules\Admin\Support;

/**
 * SRS ACC-2 — the expenses statement: VAT arithmetic and the per-invoice match
 * badge the review modal renders.
 *
 * Saudi VAT is 15% and branch managers enter the **tax-inclusive** figure off
 * the paper invoice, so pre-tax is derived by division, never by multiplication.
 * Doing it here (integer halalas, one rounding site) keeps `preTax + vat` equal
 * to the entered total for every invoice — a client-side `amount * 0.15` does not.
 */
final class ExpenseEnums
{
    /** Statutory VAT rate as an integer percentage. */
    public const VAT_PERCENT = 15;

    public const MATCH = [
        'matched' => ['labelAr' => 'مطابقة', 'icon' => '✅'],
        'mismatch' => ['labelAr' => 'غير مطابقة', 'icon' => '⚠️'],
        'missing' => ['labelAr' => 'مفقودة', 'icon' => '❌'],
    ];

    /** Badge shown above the invoice table once every row is stamped. */
    public const ALL_VERIFIED_BADGE_AR = '✅ كل الفواتير موثّقة';

    public const CONVERTED_LABEL_AR = 'محوّل';

    /**
     * Split a tax-inclusive amount into its pre-tax and VAT parts.
     *
     * `$vatOverride` carries an invoice's own VAT line when the source document
     * has one (legacy mobile expenses, zero-rated items); without it the 15%
     * statutory split applies.
     *
     * @return array{preTaxHalalas:int, vat15Halalas:int, inclTaxHalalas:int}
     */
    public static function split(int $inclusiveHalalas, ?int $vatOverride = null): array
    {
        $vat = $vatOverride ?? ($inclusiveHalalas - self::preTax($inclusiveHalalas));

        return [
            'preTaxHalalas' => $inclusiveHalalas - $vat,
            'vat15Halalas' => $vat,
            'inclTaxHalalas' => $inclusiveHalalas,
        ];
    }

    /** 11500 halalas incl. → 10000 pre-tax. */
    public static function preTax(int $inclusiveHalalas): int
    {
        return (int) round($inclusiveHalalas * 100 / (100 + self::VAT_PERCENT));
    }

    public static function match(?string $key): array
    {
        $key = $key !== null && isset(self::MATCH[$key]) ? $key : 'matched';

        return ['key' => $key] + self::MATCH[$key];
    }

    public static function matchLabelAr(?string $key): string
    {
        return self::MATCH[$key]['labelAr'] ?? $key ?? '—';
    }

    /** @return array<string, mixed> */
    public static function catalog(): array
    {
        return [
            'vatPercent' => self::VAT_PERCENT,
            'invoiceMatch' => array_map(
                fn ($key, $meta) => ['key' => $key] + $meta,
                array_keys(self::MATCH),
                array_values(self::MATCH),
            ),
            'allVerifiedBadgeAr' => self::ALL_VERIFIED_BADGE_AR,
            'convertedLabelAr' => self::CONVERTED_LABEL_AR,
        ];
    }
}
