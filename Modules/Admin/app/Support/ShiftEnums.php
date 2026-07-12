<?php

namespace Modules\Admin\Support;

/**
 * SRS §7 ACC-6 / §4.4 — shift vocabulary. The meeting model is canonical: a
 * brand runs 1–4 sequential shifts of a fixed duration starting from a first
 * time; each shift is named «الأول/الثاني/الثالث/الرابع». The prototype's
 * morning/evening split is kept as a computed alias so the old FE keeps working.
 */
final class ShiftEnums
{
    public const STATUS = [
        'active' => 'نشط',
        'late' => 'تأخير',
        'pending_review' => 'بانتظار المراجعة',
        'closed' => 'مغلق',
    ];

    /** Two-part day split (prototype) used when a shift can't be numbered. */
    public const TYPE = [
        'صباحي' => 'صباحي',
        'مسائي' => 'مسائي',
    ];

    /** Sequential shift names for the meeting model, indexed by shift number. */
    public const SEQUENTIAL_NAMES = [1 => 'الأول', 2 => 'الثاني', 3 => 'الثالث', 4 => 'الرابع'];

    public const LATE_BANNER_AR = 'انتهى وقت الشفت — لم يُغلق الصندوق بعد';

    /** Default opening float when a brand config omits it — SRS §4.4 = 500 SAR. */
    public const DEFAULT_FLOAT_HALALAS = 50000;

    public static function statusLabelAr(?string $key): ?string
    {
        return $key === null ? null : (self::STATUS[$key] ?? $key);
    }

    public static function shiftName(int $no): string
    {
        return self::SEQUENTIAL_NAMES[$no] ?? ('الوردية '.$no);
    }

    /** @return array<string, mixed> */
    public static function catalog(): array
    {
        return [
            'status' => array_map(fn ($k, $v) => ['key' => $k, 'labelAr' => $v], array_keys(self::STATUS), array_values(self::STATUS)),
            'type' => array_values(self::TYPE),
            'sequentialNames' => self::SEQUENTIAL_NAMES,
            'lateBannerAr' => self::LATE_BANNER_AR,
            'defaultFloatHalalas' => self::DEFAULT_FLOAT_HALALAS,
        ];
    }
}
