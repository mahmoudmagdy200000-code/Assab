<?php

namespace Modules\Admin\Support;

/**
 * SRS §10 SUP-1 — the supplier-portal order status vocabulary.
 *
 * A purchase Operation reaches a supplier already carrying a procurement-side
 * status (`approved` / `confirmed` / `final-approved`), then the supplier portal
 * writes `accepted` / `delivered` / `rejected`. To a supplier all of the first
 * group mean the same «مقبول» state, so the four canonical keys below each fold
 * a set of raw op statuses. This is the single source of truth for the list
 * filter, the overview KPIs and the {key,label} projection.
 *
 * `accepted` ≡ SRS state «confirmed» (the backend key stays `accepted`).
 */
final class SupplierOrderStatus
{
    /** Canonical supplier-facing states → Arabic label. */
    public const LABELS = [
        'pending' => 'في انتظار الرد',
        'accepted' => 'مقبول',
        'delivered' => 'تم التسليم',
        'rejected' => 'مرفوض',
    ];

    /**
     * Raw op statuses that fold onto each canonical key. `accepted` deliberately
     * excludes `delivered`: they are separate SUP-1.3 tabs.
     */
    public const SYNONYMS = [
        'pending' => ['pending'],
        'accepted' => ['accepted', 'confirmed', 'approved', 'final-approved'],
        'delivered' => ['delivered'],
        'rejected' => ['rejected'],
    ];

    /** Raw op status → canonical supplier key (falls back to the raw value). */
    public static function canonicalKey(?string $raw): string
    {
        foreach (self::SYNONYMS as $key => $group) {
            if (in_array($raw, $group, true)) {
                return $key;
            }
        }

        return $raw ?? 'pending';
    }

    /** {key,label} pair for a raw op status. */
    public static function present(?string $raw): array
    {
        $key = self::canonicalKey($raw);

        return ['key' => $key, 'label' => self::LABELS[$key] ?? $key];
    }

    /**
     * The raw statuses to match for a requested canonical filter.
     * null/'' → no filter; an unknown key → exact-match on itself.
     *
     * @return string[]|null
     */
    public static function synonyms(?string $canonical): ?array
    {
        if ($canonical === null || $canonical === '') {
            return null;
        }

        return self::SYNONYMS[$canonical] ?? [$canonical];
    }

    /**
     * Raw statuses that count as a completed sale (accepted + delivered) — used
     * by the SUP-1.4 sales KPIs so pending/rejected never inflate revenue.
     *
     * @return string[]
     */
    public static function fulfilled(): array
    {
        return array_merge(self::SYNONYMS['accepted'], self::SYNONYMS['delivered']);
    }

    /** Enum catalogue for the FE (key + Arabic label). */
    public static function catalog(): array
    {
        return array_map(
            fn ($key, $label) => ['key' => $key, 'label' => $label],
            array_keys(self::LABELS),
            array_values(self::LABELS),
        );
    }
}
