<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Collection;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\BranchInventoryList;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;
use Modules\Branch\Models\Branch;

/**
 * Risk / exception panel (MISSING_Dashboard §3.3). Exceptions are *derived* from
 * live operational state across modules — there is no exceptions table. Each
 * source method yields rows shaped to the spec; the controller filters/paginates.
 */
class ExceptionService
{
    /** Canonical exception-type vocabulary — shared with the lookups endpoint (§3.4). */
    public const TYPES = [
        'cash_variance' => ['labelAr' => 'فرق نقدي', 'labelEn' => 'Cash variance', 'defaultSeverity' => 'high'],
        'missing_invoice' => ['labelAr' => 'فاتورة مفقودة', 'labelEn' => 'Missing invoice', 'defaultSeverity' => 'medium'],
        'late_report' => ['labelAr' => 'تقرير متأخر', 'labelEn' => 'Late report', 'defaultSeverity' => 'medium'],
        'inventory_flag' => ['labelAr' => 'تنبيه مخزون', 'labelEn' => 'Inventory flag', 'defaultSeverity' => 'medium'],
        'asset_unconfirmed' => ['labelAr' => 'أصل غير مؤكد', 'labelEn' => 'Unconfirmed asset', 'defaultSeverity' => 'low'],
        'shift_unclosed' => ['labelAr' => 'وردية غير مُغلقة', 'labelEn' => 'Unclosed shift', 'defaultSeverity' => 'medium'],
        'quota_warning' => ['labelAr' => 'تحذير الحصة', 'labelEn' => 'Quota warning', 'defaultSeverity' => 'low'],
    ];

    /**
     * All open exception rows. Operations/Assets/Shifts inherit the tenant global
     * scope (company users auto-scoped); $companyId additionally scopes inventory
     * flags (which carry no company column) through the company's branches.
     */
    public function all(?string $companyId, ?string $branchId): Collection
    {
        $rows = collect()
            ->concat($this->cashVariances($branchId))
            ->concat($this->missingInvoices($branchId))
            ->concat($this->lateReports($branchId))
            ->concat($this->inventoryFlags($companyId, $branchId))
            ->concat($this->unconfirmedAssets($branchId))
            ->concat($this->unclosedShifts($branchId));

        return $this->withBranchNames($rows);
    }

    private function cashVariances(?string $branchId): Collection
    {
        return Operation::whereIn('module_key', ['sales', 'cash'])
            ->where('match', 'diff')
            ->whereIn('status', ['pending', 'approved'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('operation_date')->limit(500)->get()
            ->map(fn (Operation $o) => $this->row(
                'cash_variance', $o->module_key, $o->id, $o->branch_id,
                (int) ($o->payload['varianceHalalas'] ?? 0),
                optional($o->operation_date)->toIso8601String(), $o->id,
                'فارق نقدي بحاجة لتخصيص — '.$o->public_id, true, 'تخصيص',
            ));
    }

    private function missingInvoices(?string $branchId): Collection
    {
        return Operation::where('module_key', 'expenses')
            ->where('attachment_count', 0)
            ->where('status', 'pending')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('operation_date')->limit(500)->get()
            ->map(fn (Operation $o) => $this->row(
                'missing_invoice', 'expenses', $o->id, $o->branch_id, (int) $o->amount,
                optional($o->operation_date)->toIso8601String(), $o->id,
                'مصروف بدون فاتورة مرفقة — '.$o->public_id, true, 'إرفاق',
            ));
    }

    private function lateReports(?string $branchId): Collection
    {
        $cutoff = now()->subDays(2);

        return Operation::where('status', 'pending')
            ->whereNotNull('submitted_at')->where('submitted_at', '<', $cutoff)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('submitted_at')->limit(500)->get()
            ->map(fn (Operation $o) => $this->row(
                'late_report', $o->module_key, $o->id, $o->branch_id, 0,
                optional($o->submitted_at)->toIso8601String(), $o->id,
                'عملية معلّقة أكثر من يومين — '.$o->public_id, true, 'مراجعة',
            ));
    }

    private function inventoryFlags(?string $companyId, ?string $branchId): Collection
    {
        $companyBranchIds = $companyId
            ? Branch::where('asab_company_id', $companyId)->pluck('id')
            : null;

        return BranchInventoryList::where('is_flagged', true)
            ->when($companyBranchIds, fn ($q) => $q->whereIn('branch_id', $companyBranchIds))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->limit(500)->get()
            ->map(fn (BranchInventoryList $f) => $this->row(
                'inventory_flag', 'inventory', $f->id, $f->branch_id, 0,
                optional($f->updated_at)->toIso8601String(), null,
                'صنف مخزون موسوم للمراجعة', true, 'مراجعة',
            ));
    }

    private function unconfirmedAssets(?string $branchId): Collection
    {
        return Asset::whereIn('status', ['pending_branch', 'pending_accountant'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('created_at')->limit(500)->get()
            ->map(fn (Asset $a) => $this->row(
                'asset_unconfirmed', 'assets', $a->id, $a->branch_id, (int) $a->book_value,
                optional($a->created_at)->toIso8601String(), null,
                'أصل بانتظار التأكيد — '.$a->public_id, true, 'تأكيد',
            ));
    }

    private function unclosedShifts(?string $branchId): Collection
    {
        return Shift::whereIn('status', ['active', 'late'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('started_at')->limit(500)->get()
            ->map(fn (Shift $s) => $this->row(
                'shift_unclosed', 'shifts', $s->id, $s->branch_id, 0,
                optional($s->started_at)->toIso8601String(), null,
                'وردية لم تُغلق بعد', true, 'إغلاق',
            ));
    }

    /** Resolve branchName for every row in one query. */
    private function withBranchNames(Collection $rows): Collection
    {
        $names = Branch::whereIn('id', $rows->pluck('branchId')->filter()->unique()->values())->pluck('name', 'id');

        return $rows->map(function (array $r) use ($names) {
            $r['branchName'] = $r['branchId'] ? ($names[$r['branchId']] ?? null) : null;

            return $r;
        })->values();
    }

    private function row(
        string $type,
        ?string $moduleKey,
        string $idSeed,
        ?string $branchId,
        int $amountHalalas,
        ?string $occurredAt,
        ?string $linkedOperationId,
        string $messageAr,
        bool $actionable,
        ?string $actionLabel,
    ): array {
        $meta = self::TYPES[$type];

        return [
            'id' => 'exc_'.substr(md5($type.'|'.$idSeed), 0, 20),
            'type' => $type,
            'typeLabelAr' => $meta['labelAr'],
            'moduleKey' => $moduleKey,
            'severity' => $meta['defaultSeverity'],
            'branchId' => $branchId,
            'branchName' => null,
            'amountHalalas' => $amountHalalas,
            'occurredAt' => $occurredAt,
            'linkedOperationId' => $linkedOperationId,
            'messageAr' => $messageAr,
            'actionable' => $actionable,
            'actionLabel' => $actionLabel,
        ];
    }
}
