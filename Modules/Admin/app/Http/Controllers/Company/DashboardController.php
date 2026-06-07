<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\CompanyDashboardService;
use Modules\Admin\Services\PlanLimitService;
use Modules\Admin\Services\SubscriptionService;

/**
 * Company Admin dashboard (COMPANY_DASHBOARD_API_SPEC.md §5.1.1).
 */
class DashboardController extends AsabController
{
    public function __construct(
        private readonly PlanLimitService $limits,
        private readonly SubscriptionService $subscriptions,
        private readonly CompanyDashboardService $dashboard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $from = $request->query('dateFrom', now()->startOfMonth()->toDateString());
            $to = $request->query('dateTo', now()->endOfMonth()->toDateString());

            $company = AsabCompany::findOrFail($companyId);
            $sub = $this->subscriptions->current($companyId);
            $quotas = $this->limits->quotas($companyId);

            [$sales, $expenses] = $this->periodTotals($companyId, $from, $to);
            [$prevSales, $prevExpenses] = $this->previousPeriodTotals($companyId, $from, $to);
            $net = $sales - $expenses;
            $prevNet = $prevSales - $prevExpenses;

            return $this->ok([
                'company' => [
                    'id' => $company->id, 'name' => $company->name, 'logo' => $company->logo,
                    'plan' => $sub->plan->code, 'planNameAr' => $sub->plan->name_ar, 'city' => $company->city,
                ],
                'subscription' => [
                    'status' => $sub->status, 'currentPeriodEnd' => optional($sub->current_period_end)->toIso8601String(),
                    'daysRemaining' => $sub->days_remaining, 'pricePerYear' => $sub->plan->price_annual, 'autoRenew' => (bool) $sub->auto_renew,
                ],
                'quotas' => [
                    'brands' => $quotas['brands'], 'restaurants' => $quotas['restaurants'],
                    'branches' => $quotas['branches'], 'users' => $quotas['users'],
                    'storage' => ['usedGb' => 0, 'maxGb' => $sub->plan->storage_gb],
                ],
                // Existing fields kept; enriched with the spec kpis + totals (§5.2).
                'kpis' => array_merge([
                    'monthlySalesHalalas' => $sales, 'monthlyExpensesHalalas' => $expenses, 'netProfitHalalas' => $net,
                    'salesDeltaPct' => $this->delta($sales, $prevSales), 'profitDeltaPct' => $this->delta($net, $prevNet),
                    'totalBranches' => $quotas['branches']['used'],
                ], $this->dashboard->kpis($companyId, $from, $to)),
                'totals' => $this->dashboard->totals($companyId),
                'brandPerformance' => $this->dashboard->brandBreakdown($companyId, $from, $to)['brands'],
            ]);
        });
    }

    /** GET /company/me/dashboard/brand-performance (MISSING_Dashboard §5.1). */
    public function brandPerformance(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $from = $request->query('dateFrom', now()->startOfMonth()->toDateString());
            $to = $request->query('dateTo', now()->endOfMonth()->toDateString());

            return $this->ok($this->dashboard->brandBreakdown($request->user()->company_id, $from, $to));
        });
    }

    /** @return array{0:int,1:int} [sales, expenses] */
    private function periodTotals(string $companyId, string $from, string $to): array
    {
        $base = Operation::where('company_id', $companyId)->whereBetween('operation_date', [$from, $to.' 23:59:59']);

        return [
            (int) (clone $base)->where('module_key', 'sales')->sum('amount'),
            (int) (clone $base)->where('module_key', 'expenses')->sum('amount'),
        ];
    }

    /** @return array{0:int,1:int} */
    private function previousPeriodTotals(string $companyId, string $from, string $to): array
    {
        $f = \Illuminate\Support\Carbon::parse($from);
        $t = \Illuminate\Support\Carbon::parse($to);
        $len = max(1, $f->diffInDays($t) + 1);

        return $this->periodTotals($companyId, $f->copy()->subDays($len)->toDateString(), $f->copy()->subDay()->toDateString());
    }

    private function delta(int $current, int $prior): float
    {
        return $prior !== 0 ? round(($current - $prior) / abs($prior) * 100, 1) : 0.0;
    }
}
