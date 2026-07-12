<?php

namespace Modules\Admin\Support;

use Illuminate\Support\Facades\Log;

/**
 * Canonical enum maps for the approval pipeline (SRS §5). Every key the API
 * emits has exactly one Arabic label, defined here and nowhere else, so the
 * dashboard never re-implements a label map per screen.
 */
final class OperationEnums
{
    /** §5.1 — operation status. `labelStageAr` is the sales-screen wording. */
    public const STATUS = [
        'pending' => ['labelAr' => 'قيد المراجعة', 'labelShortAr' => 'معلق'],
        'approved' => ['labelAr' => 'تمت الموافقة', 'labelShortAr' => 'مقبول', 'labelStageAr' => 'معتمد - مرحلة 1'],
        'rejected' => ['labelAr' => 'مرفوض', 'labelShortAr' => 'مرفوض'],
        'final-approved' => ['labelAr' => 'معتمد نهائياً', 'labelShortAr' => 'نهائي', 'lockedLabelAr' => 'مُغلق'],
    ];

    /** §5.2 — the six lifecycle stages (م1–م6). `rejected` is off-pipeline. */
    public const STAGES = [
        'submit' => ['step' => 1, 'icon' => '📱', 'labelAr' => 'رُفع من الفرع', 'labelShortAr' => 'الرفع'],
        'review' => ['step' => 2, 'icon' => '🔍', 'labelAr' => 'قيد المراجعة', 'labelShortAr' => 'المراجعة'],
        'approved' => ['step' => 3, 'icon' => '✓', 'labelAr' => 'موافق عليه', 'labelShortAr' => 'الموافقة'],
        'final' => ['step' => 4, 'icon' => '🔒', 'labelAr' => 'معتمد نهائياً', 'labelShortAr' => 'الاعتماد'],
        'erp' => ['step' => 5, 'icon' => '🔗', 'labelAr' => 'مُرحَّل لـ ERP', 'labelShortAr' => 'ERP'],
        'reports' => ['step' => 6, 'icon' => '📊', 'labelAr' => 'تقارير ERP (قراءة)', 'labelShortAr' => 'التقارير'],
        'rejected' => ['step' => -1, 'icon' => '✗', 'labelAr' => 'مرفوض', 'labelShortAr' => 'مرفوض'],
    ];

    /** §5.2b — where the operation entered the pipeline from. */
    public const ORIGIN = [
        'mobile' => ['icon' => '📱', 'labelAr' => 'تطبيق الفرع'],
        'procurement' => ['icon' => '🛒', 'labelAr' => 'سير المشتريات'],
        'system' => ['icon' => '⚙', 'labelAr' => 'استيراد النظام'],
    ];

    /** §5.3 — reconciliation outcome. */
    public const MATCH_STATUS = [
        'exact' => ['labelAr' => 'متطابق'],
        'review' => ['labelAr' => 'يحتاج مراجعة'],
        'diff' => ['labelAr' => 'فرق في الكمية'],
    ];

    /** §5.2c — per-branch/day rollup state. `erp_imported` is reserved (future). */
    public const ROLLUP = [
        'empty' => ['step' => 0, 'labelAr' => 'لا بيانات', 'subLabelAr' => 'لم يُرفع أي بيان'],
        'incomplete' => ['step' => 1, 'labelAr' => 'غير مكتمل', 'subLabelAr' => 'توجد بيانات معلقة في المراجعة'],
        'ready_consolidation' => ['step' => 2, 'labelAr' => 'جاهز للتجميع', 'subLabelAr' => 'كل البيانات راجعة — ابدأ التجميع'],
        'consolidated' => ['step' => 3, 'labelAr' => 'مُجمَّع', 'subLabelAr' => 'قيد محاسبي مُغلق — جاهز للدفعة'],
        'ready_erp' => ['step' => 4, 'labelAr' => 'جاهز لـ ERP', 'subLabelAr' => 'دفعة جاهزة للإرسال'],
        'exported' => ['step' => 5, 'labelAr' => 'مُصدَّر', 'subLabelAr' => 'مُرحَّل في ERP — انتظار التأكيد'],
        'erp_imported' => ['step' => 6, 'labelAr' => 'مُستورَد في ERP ★', 'subLabelAr' => 'ERP أكّد الاستلام — مرحلة مستقبلية'],
    ];

    /** §5.4 — rejection reasons offered on every module. */
    public const REJECTION_REASONS = [
        'incomplete_data' => 'بيانات غير مكتملة',
        'missing_invoice' => 'فاتورة مفقودة أو غير واضحة',
        'amount_mismatch' => 'تناقض في المبالغ',
        'quantity_diff' => 'فرق في الكميات',
        'invalid_date' => 'تاريخ غير صحيح',
        'unapproved_supplier' => 'مورد غير معتمد',
        'other' => 'أخرى',
    ];

    /** §5.4 — extra reasons the sales screens add to the generic list. */
    public const REJECTION_REASONS_BY_MODULE = [
        'sales' => [
            'missing_pos_report' => 'تقرير POS مفقود',
            'missing_bank_statement' => 'كشف البنك غير مرفق',
        ],
    ];

    /** @return array{key:string, labelAr:string, labelShortAr:string} */
    public static function status(?string $key): array
    {
        $meta = self::STATUS[$key] ?? ['labelAr' => (string) $key, 'labelShortAr' => (string) $key];

        return ['key' => (string) $key, 'labelAr' => $meta['labelAr'], 'labelShortAr' => $meta['labelShortAr']];
    }

    /** @return array{key:string, labelAr:string, icon:string} */
    public static function origin(?string $key): array
    {
        $meta = self::ORIGIN[$key] ?? ['labelAr' => (string) $key, 'icon' => '•'];

        return ['key' => (string) $key, 'labelAr' => $meta['labelAr'], 'icon' => $meta['icon']];
    }

    /** @return array{key:?string, labelAr:?string} */
    public static function match(?string $key): array
    {
        if ($key === null) {
            return ['key' => null, 'labelAr' => null];
        }

        return ['key' => $key, 'labelAr' => self::MATCH_STATUS[$key]['labelAr'] ?? $key];
    }

    /** @return array{key:string, labelAr:string, subLabelAr:string, step:int} */
    public static function rollup(string $key): array
    {
        $meta = self::ROLLUP[$key] ?? self::ROLLUP['empty'];

        return ['key' => $key, 'labelAr' => $meta['labelAr'], 'subLabelAr' => $meta['subLabelAr'], 'step' => $meta['step']];
    }

    public static function stageIcon(string $stage): string
    {
        return self::STAGES[$stage]['icon'] ?? '•';
    }

    /**
     * The lifecycle stage an operation currently sits on — what the «م3 · الموافقة»
     * badge renders. Rejected is off-pipeline (step -1); a posted operation has
     * reached ERP (م5).
     *
     * @return array{key:string, step:int, icon:string, labelAr:string, labelShortAr:string}
     */
    public static function stageFor(string $status, bool $erpPosted = false): array
    {
        $key = match (true) {
            $status === 'rejected' => 'rejected',
            $erpPosted => 'erp',
            $status === 'final-approved' => 'final',
            $status === 'approved' => 'approved',
            default => 'review',
        };

        return ['key' => $key] + self::STAGES[$key];
    }

    public static function isValidOrigin(string $origin): bool
    {
        return array_key_exists($origin, self::ORIGIN);
    }

    /**
     * The rejection reasons a module accepts: the generic list plus any
     * module-specific additions.
     *
     * @return array<string, string> key => Arabic label
     */
    public static function rejectionReasons(?string $moduleKey = null): array
    {
        return array_merge(
            self::REJECTION_REASONS,
            self::REJECTION_REASONS_BY_MODULE[$moduleKey] ?? [],
        );
    }

    /**
     * Resolve a client-supplied reason to its canonical key + label.
     *
     * Accepts the enum key ("missing_invoice") and — deprecated, for one
     * release — the raw Arabic label the pre-T03.4 clients sent verbatim.
     * Returns null when the reason belongs to no list for this module.
     *
     * @return array{key:string, labelAr:string}|null
     */
    public static function resolveRejectionReason(string $reason, ?string $moduleKey = null): ?array
    {
        $reasons = self::rejectionReasons($moduleKey);
        $reason = trim($reason);

        if (isset($reasons[$reason])) {
            return ['key' => $reason, 'labelAr' => $reasons[$reason]];
        }

        $key = array_search($reason, $reasons, true);
        if ($key !== false) {
            Log::warning('asab.reject.legacy_reason_label', ['reason' => $reason, 'moduleKey' => $moduleKey]);

            return ['key' => $key, 'labelAr' => $reason];
        }

        return null;
    }

    /** Reverse-lookup so stored Arabic labels can still be presented with a key. */
    public static function rejectionReasonKey(?string $storedLabel, ?string $moduleKey = null): ?string
    {
        if ($storedLabel === null || $storedLabel === '') {
            return null;
        }
        $key = array_search(trim($storedLabel), self::rejectionReasons($moduleKey), true);

        return $key === false ? null : $key;
    }

    /**
     * Every map the dashboard needs to render filters and badges, in one call.
     *
     * @return array<string, mixed>
     */
    public static function catalog(): array
    {
        $rows = fn (array $map) => array_map(
            fn ($key, $meta) => ['key' => $key] + $meta,
            array_keys($map),
            array_values($map),
        );

        return [
            'status' => $rows(self::STATUS),
            'stages' => $rows(self::STAGES),
            'origin' => $rows(self::ORIGIN),
            'match' => $rows(self::MATCH_STATUS),
            'rollup' => $rows(self::ROLLUP),
            'rejectionReasons' => $rows(array_map(fn ($l) => ['labelAr' => $l], self::REJECTION_REASONS)),
            'rejectionReasonsByModule' => array_map(
                fn ($map) => $rows(array_map(fn ($l) => ['labelAr' => $l], $map)),
                self::REJECTION_REASONS_BY_MODULE,
            ),
        ];
    }
}
