<?php

namespace Modules\Admin\Support;

/**
 * SRS §4.3 — the collection channels a branch reconciles at end of shift.
 *
 * The prototype's «جدول المقارنة والتسوية» compares, per channel, what the
 * branch entered against what the POS/bank/aggregator reports. Keys are stable;
 * Arabic labels live here and nowhere else. Free-text channel names used to
 * fragment the data («هنقرستيشن» vs "Hunger Station"), which broke the P&L
 * channel rollups downstream — hence the alias table.
 */
final class SalesChannels
{
    public const CHANNELS = [
        'pos' => ['labelAr' => 'كاشير (POS)', 'icon' => '🖥️', 'group' => 'core'],
        'cash' => ['labelAr' => 'نقدي (صندوق)', 'icon' => '💵', 'group' => 'core'],
        'bank' => ['labelAr' => 'بنكي / بنك الرياض (مدى)', 'icon' => '🏦', 'group' => 'core'],
        'talabat' => ['labelAr' => 'طلبات', 'icon' => '🔴', 'group' => 'delivery'],
        'hungerstation' => ['labelAr' => 'هنقرستيشن', 'icon' => '🟠', 'group' => 'delivery'],
        'jahez' => ['labelAr' => 'جاهز', 'icon' => '🟡', 'group' => 'delivery'],
        'toyou' => ['labelAr' => 'تو يو (ToYou)', 'icon' => '🔵', 'group' => 'delivery'],
        'ninja' => ['labelAr' => 'نينجا', 'icon' => '⚫', 'group' => 'delivery'],
    ];

    /** Free-text names the pre-T04 clients sent, folded onto canonical keys. */
    private const ALIASES = [
        'كاشير' => 'pos', 'كاشير (pos)' => 'pos', 'pos' => 'pos',
        'نقدي' => 'cash', 'نقدي (صندوق)' => 'cash', 'cash' => 'cash', 'الصندوق' => 'cash',
        'بنكي' => 'bank', 'بنك' => 'bank', 'مدى' => 'bank', 'mada' => 'bank', 'bank' => 'bank',
        'بنكي / بنك الرياض (مدى)' => 'bank', 'بنك الرياض' => 'bank', 'card' => 'bank', 'شبكة' => 'bank',
        'طلبات' => 'talabat', 'talabat' => 'talabat',
        'هنقرستيشن' => 'hungerstation', 'hungerstation' => 'hungerstation', 'hunger station' => 'hungerstation',
        'جاهز' => 'jahez', 'jahez' => 'jahez',
        'تو يو' => 'toyou', 'تو يو (toyou)' => 'toyou', 'toyou' => 'toyou', 'to you' => 'toyou',
        'نينجا' => 'ninja', 'ninja' => 'ninja',
    ];

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::CHANNELS);
    }

    /** Canonical key for an enum key or a legacy free-text name; null when unknown. */
    public static function resolve(string $keyOrName): ?string
    {
        $needle = trim($keyOrName);
        if (self::exists($needle)) {
            return $needle;
        }

        return self::ALIASES[mb_strtolower($needle)] ?? null;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::CHANNELS);
    }

    /** @return string[] the aggregator channels — the «تطبيقات التوصيل» section */
    public static function deliveryKeys(): array
    {
        return array_keys(array_filter(self::CHANNELS, fn ($c) => $c['group'] === 'delivery'));
    }

    public static function labelAr(string $key): string
    {
        return self::CHANNELS[$key]['labelAr'] ?? $key;
    }

    public static function icon(string $key): string
    {
        return self::CHANNELS[$key]['icon'] ?? '•';
    }

    /** @return array<int, array{key:string, labelAr:string, icon:string, group:string}> */
    public static function catalog(): array
    {
        return array_map(
            fn ($key, $meta) => ['key' => $key] + $meta,
            array_keys(self::CHANNELS),
            array_values(self::CHANNELS),
        );
    }
}
