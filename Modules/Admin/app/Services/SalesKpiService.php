<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;

/**
 * SRS ACC-1.1 + ACC-1.5 — the four sales KPI cards and the variance banner
 * («تنبيه: توجد فروق في المبيعات»).
 *
 * Sales totals come from the operation's locked `amount`; collected and
 * variance come from the accountant's reconciliation, so an unreconciled
 * operation contributes to sales but not to the variance case count.
 */
class SalesKpiService
{
    /**
     * @param  string[]|null  $branchIds  null = every branch of the company
     * @return array<string, mixed>
     */
    public function forDate(string $companyId, ?array $branchIds, ?string $date = null): array
    {
        $day = Carbon::parse($date ?? 'today');

        $today = $this->operations($companyId, $branchIds, $day);
        $yesterday = $this->operations($companyId, $branchIds, $day->copy()->subDay());

        $totalSales = (int) $today->sum('amount');
        $priorSales = (int) $yesterday->sum('amount');

        $collected = 0;
        $varianceByBranch = [];
        foreach ($today as $op) {
            $reconciliation = $op->payload['reconciliation'] ?? null;
            if (! is_array($reconciliation) || ! isset($reconciliation['channels'])) {
                continue;
            }
            $collected += (int) ($reconciliation['totalCollectionHalalas'] ?? 0);
            $variance = (int) ($reconciliation['varianceHalalas'] ?? 0);
            if ($variance !== 0) {
                $varianceByBranch[$op->branch_id] = ($varianceByBranch[$op->branch_id] ?? 0) + $variance;
            }
        }

        $branchIdsToday = $today->pluck('branch_id')->filter()->unique();
        $branchNames = Branch::whereIn('id', $branchIdsToday)->pluck('name', 'id');

        return [
            'date' => $day->toDateString(),
            'totalSalesHalalas' => $totalSales,
            'branchCount' => $branchIdsToday->count(),
            'trendPct' => $priorSales === 0 ? null : round(($totalSales - $priorSales) / $priorSales * 100, 1),
            'totalCollectedHalalas' => $collected,
            'totalVarianceHalalas' => array_sum($varianceByBranch),
            'varianceCaseCount' => count($varianceByBranch),
            'zeroVarianceBranchCount' => max(0, $branchIdsToday->count() - count($varianceByBranch)),
            'varianceBranches' => array_values(array_map(
                fn ($branchId, $variance) => [
                    'branchId' => $branchId,
                    'name' => $branchNames[$branchId] ?? '—',
                    'varianceHalalas' => $variance,
                ],
                array_keys($varianceByBranch),
                array_values($varianceByBranch),
            )),
        ];
    }

    /**
     * @param  string[]|null  $branchIds
     * @return \Illuminate\Database\Eloquent\Collection<int, Operation>
     */
    private function operations(string $companyId, ?array $branchIds, Carbon $day)
    {
        return Operation::query()
            ->where('company_id', $companyId)
            ->where('module_key', 'sales')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->whereDate('operation_date', $day->toDateString())
            ->get(['id', 'branch_id', 'amount', 'payload']);
    }
}
