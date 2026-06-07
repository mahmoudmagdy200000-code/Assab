<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;

/**
 * Company-admin dashboard aggregation (MISSING_Dashboard §5). Per-brand
 * sales/target/profit breakdown + top-level KPIs and entity totals. All
 * computations are scoped explicitly by companyId (zero-trust).
 */
class CompanyDashboardService
{
    /** §5.1 per-brand performance + branch completion summary. */
    public function brandBreakdown(string $companyId, string $from, string $to): array
    {
        $sums = $this->branchSums($companyId, $from, $to);
        $branches = Branch::where('asab_company_id', $companyId)->get(['id', 'asab_brand_id', 'asab_monthly_target']);
        $branchesByBrand = $branches->groupBy('asab_brand_id');

        $totalBranches = $branches->count();
        $branchesAboveTarget = 0;
        $branchesWithActivity = 0;

        $brands = AsabBrand::where('company_id', $companyId)->get()
            ->map(function (AsabBrand $b) use ($branchesByBrand, $sums, &$branchesAboveTarget, &$branchesWithActivity) {
                $brandBranches = $branchesByBrand[$b->id] ?? collect();
                $sales = 0;
                $expenses = 0;
                $target = 0;
                foreach ($brandBranches as $br) {
                    $s = (int) ($sums[$br->id]['sales'] ?? 0);
                    $e = (int) ($sums[$br->id]['expenses'] ?? 0);
                    $t = (int) ($br->asab_monthly_target ?? 0);
                    $sales += $s;
                    $expenses += $e;
                    $target += $t;
                    if ($t > 0 && $s >= $t) {
                        $branchesAboveTarget++;
                    }
                    if ($s > 0 || $e > 0) {
                        $branchesWithActivity++;
                    }
                }

                return [
                    'id' => $b->id,
                    'name' => $b->name,
                    'abbr' => $b->abbr,
                    'color' => $b->color,
                    'branchesCount' => $brandBranches->count(),
                    'monthlySalesHalalas' => $sales,
                    'monthlyTargetHalalas' => $target,
                    'achievementPct' => $target > 0 ? (int) round($sales / $target * 100) : 0,
                    'monthlyExpensesHalalas' => $expenses,
                    'netProfitHalalas' => $sales - $expenses,
                ];
            })->values()->all();

        return [
            'brands' => $brands,
            'branchCompletionRate' => $totalBranches > 0 ? (int) round($branchesWithActivity / $totalBranches * 100) : 0,
            'branchesAboveTarget' => $branchesAboveTarget,
            'totalBranchesCount' => $totalBranches,
        ];
    }

    /** §5.2 top-level KPI block (with vs-last-month deltas + completion summary). */
    public function kpis(string $companyId, string $from, string $to): array
    {
        [$sales, $expenses] = $this->periodTotals($companyId, $from, $to);
        [$prevSales, $prevExpenses] = $this->previousPeriodTotals($companyId, $from, $to);
        $net = $sales - $expenses;
        $prevNet = $prevSales - $prevExpenses;
        $breakdown = $this->brandBreakdown($companyId, $from, $to);

        return [
            'totalSalesHalalas' => $sales,
            'totalExpensesHalalas' => $expenses,
            'netProfitHalalas' => $net,
            'salesDeltaVsLastMonthPct' => $this->delta($sales, $prevSales),
            'profitDeltaVsLastMonthPct' => $this->delta($net, $prevNet),
            'branchCompletionRate' => $breakdown['branchCompletionRate'],
            'branchesAboveTarget' => $breakdown['branchesAboveTarget'],
        ];
    }

    /** §5.2 entity totals. */
    public function totals(string $companyId): array
    {
        return [
            'brandsCount' => AsabBrand::where('company_id', $companyId)->count(),
            'restaurantsCount' => AsabRestaurant::where('company_id', $companyId)->count(),
            'branchesCount' => Branch::where('asab_company_id', $companyId)->count(),
            'usersCount' => AsabUser::where('company_id', $companyId)->count(),
        ];
    }

    /** @return array<string, array{sales?:int, expenses?:int}> keyed by branch id */
    private function branchSums(string $companyId, string $from, string $to): array
    {
        $rows = Operation::where('company_id', $companyId)
            ->whereBetween('operation_date', [$from, $this->endOfDay($to)])
            ->whereIn('module_key', ['sales', 'expenses'])
            ->selectRaw('branch_id, module_key, SUM(amount) as total')
            ->groupBy('branch_id', 'module_key')->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->branch_id][$row->module_key === 'sales' ? 'sales' : 'expenses'] = (int) $row->total;
        }

        return $out;
    }

    /** @return array{0:int,1:int} [sales, expenses] */
    private function periodTotals(string $companyId, string $from, string $to): array
    {
        $base = Operation::where('company_id', $companyId)->whereBetween('operation_date', [$from, $this->endOfDay($to)]);

        return [
            (int) (clone $base)->where('module_key', 'sales')->sum('amount'),
            (int) (clone $base)->where('module_key', 'expenses')->sum('amount'),
        ];
    }

    /** @return array{0:int,1:int} */
    private function previousPeriodTotals(string $companyId, string $from, string $to): array
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        $len = max(1, $f->diffInDays($t) + 1);

        return $this->periodTotals($companyId, $f->copy()->subDays($len)->toDateString(), $f->copy()->subDay()->toDateString());
    }

    private function endOfDay(string $to): string
    {
        return str_contains($to, ':') ? $to : $to.' 23:59:59';
    }

    private function delta(int $current, int $prior): float
    {
        return $prior !== 0 ? round(($current - $prior) / abs($prior) * 100, 1) : 0.0;
    }
}
