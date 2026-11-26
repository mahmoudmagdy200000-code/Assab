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
        'pending' => ['pending', 'emergency', 'sent_awaiting_response'],
        'accepted' => ['accepted', 'confirmed', 'preparing', 'on_the_way', 'approved', 'final-approved'],
        'delivered' => ['delivered', 'closed'],
        'rejected' => ['rejected', 'cancelled_by_supplier'],
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

    /**
     * Scope a PurchaseOrder query to orders legitimately visible to a supplier user:
     *  - direct_supplier orders (submitted, not draft)
     *  - via_purchasing_officer / multiple_sources orders already dispatched in a group
     *  - strictly isolated to each owned supplier's company_id (or cross-company when company_id is null)
     */
    public static function scopePurchaseOrdersForUser(\Illuminate\Database\Eloquent\Builder $q, string $userId): \Illuminate\Database\Eloquent\Builder
    {
        $rows = \Modules\Admin\Models\AsabSupplier::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->get(['id', 'legacy_supplier_id', 'company_id']);

        $pairs = [];
        foreach ($rows as $row) {
            $legacyId = $row->legacy_supplier_id;
            if (! $legacyId && \Modules\Supplier\Models\Supplier::whereKey($row->id)->exists()) {
                $legacyId = $row->id;
            }
            if ($legacyId) {
                $pairs[] = [$legacyId, $row->company_id];
            }
        }

        if ($pairs === []) {
            return $q->whereRaw('1 = 0');
        }

        return $q
            ->where(function (\Illuminate\Database\Eloquent\Builder $outer) use ($pairs) {
                foreach ($pairs as [$legacyId, $companyId]) {
                    $outer->orWhere(function (\Illuminate\Database\Eloquent\Builder $w) use ($legacyId, $companyId) {
                        $w->where('supplier_id', $legacyId);
                        if ($companyId !== null) {
                            $w->whereHas('branch', fn (\Illuminate\Database\Eloquent\Builder $b) => $b->where('asab_company_id', $companyId));
                        }
                    });
                }
            })
            ->where(function (\Illuminate\Database\Eloquent\Builder $lifecycle) {
                $lifecycle->where(function (\Illuminate\Database\Eloquent\Builder $direct) {
                    $direct->where('order_type', \Modules\Purchase\Enums\OrderType::DIRECT_SUPPLIER->value)
                        ->where('status', '!=', \Modules\Purchase\Enums\OrderStatus::DRAFT->value);
                })->orWhere(function (\Illuminate\Database\Eloquent\Builder $proc) {
                    $proc->whereIn('order_type', [
                        \Modules\Purchase\Enums\OrderType::VIA_PURCHASING_OFFICER->value,
                        \Modules\Purchase\Enums\OrderType::MULTIPLE_SOURCES->value,
                    ])->whereNotNull('group_id');
                });
            });
    }
}
