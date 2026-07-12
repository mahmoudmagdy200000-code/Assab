<?php

namespace Modules\Admin\Support;

/**
 * SRS §7 ACC-7.2/7.4 — the employee-statement movement categories, single source
 * of truth for `category` → Arabic label and the SAL-/ADV-/DED-/SET- ref prefix.
 *
 * The four *system* categories are posted only by their operation flows and share
 * their keys with the services that already write them, so this enum never renames
 * a shipped key (that would break T04/T07/T08 and the published FE docs):
 *   sales_variance      ← SalesVarianceService      «فرق مبيعات»
 *   cash_variance       ← ShiftCloseService         «خصم فرق كاش»  (SRS alias: cash_gap_deduction)
 *   waste_charge        ← WasteEnums                «خصم هدر»      (SRS alias: waste_deduction)
 *   inventory_variance  ← InventoryReconciliation   «فرق جرد يومي»
 *
 * The *manual* categories are the only ones an accountant may post through
 * addMovement / settle-balance; the system keys are rejected there to keep the
 * ledger honest — a cash-gap charge can only enter via a shift operation.
 */
final class EmployeeMovementCategory
{
    /** category key → Arabic label (render verbatim). */
    public const LABELS = [
        'sales_variance' => 'فرق مبيعات',
        'cash_variance' => 'خصم فرق كاش',
        'waste_charge' => 'خصم هدر',
        'inventory_variance' => 'فرق جرد يومي',
        'advance' => 'سلفة',
        'bonus' => 'مكافأة',
        'absence' => 'غياب',
        'receipts_shortage' => 'نقص إيصالات',
        'settlement' => 'تسوية',
        'deduction' => 'خصم',
    ];

    /** Posted only by operation flows — never accepted from a manual endpoint. */
    public const SYSTEM = ['sales_variance', 'cash_variance', 'waste_charge', 'inventory_variance'];

    /** Human ref prefix per category (statement `ref` column, e.g. ADV-9F3A21). */
    public const REF_PREFIXES = [
        'sales_variance' => 'SLV',
        'cash_variance' => 'CSH',
        'waste_charge' => 'WST',
        'inventory_variance' => 'INV',
        'advance' => 'ADV',
        'bonus' => 'BON',
        'absence' => 'ABS',
        'receipts_shortage' => 'RCP',
        'settlement' => 'SET',
        'deduction' => 'DED',
    ];

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::LABELS);
    }

    public static function isManual(string $key): bool
    {
        return self::isKnown($key) && ! in_array($key, self::SYSTEM, true);
    }

    /** @return string[] the categories a human may post via addMovement. */
    public static function manualKeys(): array
    {
        return array_values(array_diff(array_keys(self::LABELS), self::SYSTEM));
    }

    public static function labelAr(?string $key): ?string
    {
        return $key === null ? null : (self::LABELS[$key] ?? null);
    }

    /** A stable-ish human reference for a freshly posted movement. */
    public static function makeRef(string $key, string $seed): string
    {
        $prefix = self::REF_PREFIXES[$key] ?? 'MOV';

        return $prefix.'-'.strtoupper(substr(str_replace('-', '', $seed), 0, 6));
    }

    /** @return array<int, array{key:string, labelAr:string}> full catalog for FE dropdowns. */
    public static function catalog(): array
    {
        return array_map(fn ($k) => ['key' => $k, 'labelAr' => self::LABELS[$k]], array_keys(self::LABELS));
    }

    /**
     * Balance caption per SRS §6 (balance = Σcredit − Σdebit).
     *
     * @return array{key:string, labelAr:string}
     */
    public static function balanceCaption(int $balance): array
    {
        return match (true) {
            $balance < 0 => ['key' => 'debtor', 'labelAr' => 'مديون للشركة'],
            $balance > 0 => ['key' => 'creditor', 'labelAr' => 'دائن'],
            default => ['key' => 'settled', 'labelAr' => 'لا يوجد رصيد'],
        };
    }
}
