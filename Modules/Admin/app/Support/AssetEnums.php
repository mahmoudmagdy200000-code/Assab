<?php

namespace Modules\Admin\Support;

/**
 * SRS §4.2 — the fixed-assets register vocabulary.
 *
 * Two status families coexist and must not be conflated: `WORKFLOW` is the
 * branch↔accountant confirmation chain a newly registered asset walks through,
 * `LIFECYCLE` is what the register's status pills filter on afterwards. The
 * register carries both, so validation accepts the union.
 */
final class AssetEnums
{
    public const CATEGORIES = [
        'kitchen' => ['labelAr' => 'معدات مطبخ', 'depreciationRate' => 20],
        'tech' => ['labelAr' => 'تقنية وأجهزة', 'depreciationRate' => 25],
        'furniture' => ['labelAr' => 'أثاث ومفروشات', 'depreciationRate' => 10],
        'vehicles' => ['labelAr' => 'مركبات', 'depreciationRate' => 20],
        'construction' => ['labelAr' => 'صيانة وإنشاءات', 'depreciationRate' => 15],
        'other' => ['labelAr' => 'أخرى', 'depreciationRate' => 15],
    ];

    /** The conversion wizard's «العمر الإنتاجي» dropdown: 2–7 years. */
    public const USEFUL_LIFE_MONTHS = [24, 36, 48, 60, 72, 84];

    public const LIFECYCLE = [
        'active' => 'نشط',
        'maintenance' => 'صيانة',
        'retired' => 'مُهلك',
    ];

    public const WORKFLOW = [
        'pending_branch' => 'بانتظار تأكيد الفرع',
        'pending_accountant' => 'بانتظار تأكيد المحاسب',
        'confirmed' => 'مؤكد',
    ];

    public const DRAFT_STATUS = [
        'draft' => 'في انتظار التأكيد',
        'confirmed' => 'مؤكد',
        'discarded' => 'مُتجاهلة',
    ];

    /** @return array<string, string> every status an asset row may carry */
    public static function statuses(): array
    {
        return self::LIFECYCLE + self::WORKFLOW;
    }

    public static function statusLabelAr(?string $key): ?string
    {
        return $key === null ? null : (self::statuses()[$key] ?? $key);
    }

    public static function draftStatusLabelAr(?string $key): ?string
    {
        return $key === null ? null : (self::DRAFT_STATUS[$key] ?? $key);
    }

    /**
     * Imported and pre-T05 rows carry free-text categories («غير مصنف»), so an
     * unknown key echoes back rather than resolving to «أخرى» — the register
     * must show what is actually stored.
     */
    public static function categoryLabelAr(?string $key): ?string
    {
        return $key === null ? null : (self::CATEGORIES[$key]['labelAr'] ?? $key);
    }

    /** `in:24,36,48,60,72,84` */
    public static function usefulLifeRule(): string
    {
        return 'in:'.implode(',', self::USEFUL_LIFE_MONTHS);
    }

    /** `in:active,maintenance,retired,pending_branch,…` */
    public static function statusRule(): string
    {
        return 'in:'.implode(',', array_keys(self::statuses()));
    }

    /** Straight-line depreciation over the useful life, in halalas. */
    public static function monthlyDepreciation(int $costHalalas, ?int $usefulLifeMonths): int
    {
        return (int) round($costHalalas / max(1, (int) $usefulLifeMonths));
    }

    public static function annualDepreciation(int $costHalalas, ?int $usefulLifeMonths): int
    {
        return (int) round($costHalalas * 12 / max(1, (int) $usefulLifeMonths));
    }

    /** @return array<string, mixed> */
    public static function catalog(): array
    {
        $labelled = fn (array $map) => array_map(
            fn ($key, $labelAr) => ['key' => $key, 'labelAr' => $labelAr],
            array_keys($map),
            array_values($map),
        );

        return [
            'categories' => array_map(
                fn ($key, $meta) => ['key' => $key, 'id' => $key, 'name' => $meta['labelAr']] + $meta,
                array_keys(self::CATEGORIES),
                array_values(self::CATEGORIES),
            ),
            'usefulLifeMonths' => array_map(
                fn (int $m) => ['value' => $m, 'labelAr' => $m === 24 ? 'سنتان' : intdiv($m, 12).' سنوات'],
                self::USEFUL_LIFE_MONTHS,
            ),
            'lifecycleStatus' => $labelled(self::LIFECYCLE),
            'workflowStatus' => $labelled(self::WORKFLOW),
            'draftStatus' => $labelled(self::DRAFT_STATUS),
        ];
    }
}
