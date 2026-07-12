<?php

namespace Modules\Admin\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Admin\Models\Operation;

/**
 * Pipeline overview + module aggregation (MISSING_Dashboard §3.1–3.2).
 * Company users are scoped by the Operation tenant global scope; an admin may
 * pass an explicit companyId for a cross-tenant view.
 */
class PipelineService
{
    private const MODULES = [
        'sales' => 'المبيعات',
        'expenses' => 'المصروفات',
        'purchases' => 'المشتريات',
        'inventory' => 'المخزون',
        'shifts' => 'الورديات',
        'employees' => 'الموظفين',
        'cash' => 'النقدية',
        'waste' => 'الهدر',
    ];

    /**
     * Funnel stage counts + today's throughput + average cycle time.
     *
     * @param  string[]|null  $branchIds  assigned-branch constraint; null = company-wide
     */
    public function overview(?string $companyId, ?array $branchIds = null): array
    {
        $scoped = fn () => $this->scoped($companyId, $branchIds);

        $byStatus = $scoped()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $total = (int) $scoped()->count();
        $erpPosted = (int) $scoped()->where('erp_posted', true)->count();

        $today = now()->startOfDay();
        $submittedToday = (int) $scoped()->where('submitted_at', '>=', $today)->count();
        $completedToday = (int) $scoped()->where('final_approved_at', '>=', $today)->count();

        $completed = $scoped()
            ->whereNotNull('submitted_at')->whereNotNull('final_approved_at')
            ->limit(2000)->get(['submitted_at', 'final_approved_at']);
        $avgCycleTimeHours = $completed->isEmpty()
            ? 0.0
            : round($completed->avg(fn ($o) => $o->submitted_at->diffInMinutes($o->final_approved_at)) / 60, 1);

        return [
            'stages' => [
                ['key' => 'submitted', 'labelAr' => 'تم الرفع', 'count' => $total],
                ['key' => 'pending', 'labelAr' => 'بانتظار المراجعة', 'count' => (int) ($byStatus['pending'] ?? 0)],
                ['key' => 'approved', 'labelAr' => 'معتمد محاسب', 'count' => (int) ($byStatus['approved'] ?? 0)],
                ['key' => 'final-approved', 'labelAr' => 'اعتماد نهائي', 'count' => (int) ($byStatus['final-approved'] ?? 0)],
                ['key' => 'erp-posted', 'labelAr' => 'مُرحَّل ERP', 'count' => $erpPosted],
                ['key' => 'rejected', 'labelAr' => 'مرفوض', 'count' => (int) ($byStatus['rejected'] ?? 0)],
            ],
            'throughputToday' => ['submittedCount' => $submittedToday, 'completedCount' => $completedToday],
            'avgCycleTimeHours' => $avgCycleTimeHours,
        ];
    }

    /**
     * Per-module rollup (counts + amounts) for the aggregation grid.
     *
     * @param  string[]|null  $branchIds  assigned-branch constraint; null = company-wide
     */
    public function aggregation(?string $companyId, ?string $from, ?string $to, ?array $branchIds = null): array
    {
        $modules = [];
        foreach (self::MODULES as $key => $labelAr) {
            $scope = function () use ($companyId, $branchIds, $key, $from, $to): Builder {
                $q = $this->scoped($companyId, $branchIds)->where('module_key', $key);
                if ($from) {
                    $q->whereDate('operation_date', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('operation_date', '<=', $to);
                }

                return $q;
            };
            $byStatus = $scope()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

            $modules[] = [
                'moduleKey' => $key,
                'moduleLabelAr' => $labelAr,
                'totalCount' => (int) $scope()->count(),
                'totalAmountHalalas' => (int) $scope()->sum('amount'),
                'pendingCount' => (int) ($byStatus['pending'] ?? 0),
                'approvedCount' => (int) ($byStatus['approved'] ?? 0),
                'rejectedCount' => (int) ($byStatus['rejected'] ?? 0),
                'finalApprovedCount' => (int) ($byStatus['final-approved'] ?? 0),
            ];
        }

        return $modules;
    }

    /**
     * Fresh query. Company users: rely on the tenant global scope. Admin with an
     * explicit companyId: bypass the scope and filter to that company. A scoped
     * accountant is additionally pinned to their assigned branches (zero-trust).
     *
     * @param  string[]|null  $branchIds
     */
    private function scoped(?string $companyId, ?array $branchIds = null): Builder
    {
        $q = $companyId
            ? Operation::withoutGlobalScopes()->whereNull('deleted_at')->where('company_id', $companyId)
            : Operation::query();

        if ($branchIds !== null) {
            $q->whereIn('branch_id', $branchIds);
        }

        return $q;
    }
}
