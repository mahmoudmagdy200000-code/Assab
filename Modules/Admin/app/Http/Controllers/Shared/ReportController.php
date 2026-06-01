<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;

/**
 * Reports (BACKEND_API_SPEC.md §7.5). Each returns { reportId, generatedAt, data, downloadUrl }.
 * Figures computed from operations where applicable.
 */
class ReportController extends AsabController
{
    public function profitLoss(Request $request): JsonResponse
    {
        return $this->report($request, function ($scope) {
            $sales = (int) (clone $scope)->where('module_key', 'sales')->sum('amount');
            $expenses = (int) (clone $scope)->where('module_key', 'expenses')->sum('amount');
            $purchases = (int) (clone $scope)->where('module_key', 'purchases')->sum('amount');

            return ['income' => $sales, 'expenses' => $expenses, 'purchases' => $purchases, 'netProfit' => $sales - $expenses - $purchases];
        });
    }

    public function salesSummary(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => [
            'totalSales' => (int) (clone $s)->where('module_key', 'sales')->sum('amount'),
            'count' => (clone $s)->where('module_key', 'sales')->count(),
        ]);
    }

    public function expenseSummary(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => [
            'totalExpenses' => (int) (clone $s)->where('module_key', 'expenses')->sum('amount'),
            'count' => (clone $s)->where('module_key', 'expenses')->count(),
        ]);
    }

    public function inventoryValuation(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => ['valuation' => (int) (clone $s)->where('module_key', 'inventory')->sum('amount')]);
    }

    public function payroll(Request $request): JsonResponse
    {
        return $this->report($request, fn () => ['totalPayroll' => (int) \Modules\Admin\Models\Employee::sum('monthly_salary')]);
    }

    public function wasteAnalysis(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => ['totalWaste' => (int) (clone $s)->where('module_key', 'waste')->sum('amount')]);
    }

    public function supplierPerformance(Request $request): JsonResponse
    {
        return $this->report($request, fn () => ['suppliers' => []]);
    }

    public function menuEngineering(Request $request): JsonResponse
    {
        return $this->report($request, fn () => ['items' => []]);
    }

    public function breakeven(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => [
            'fixedCosts' => (int) (clone $s)->where('module_key', 'expenses')->sum('amount'),
            'revenue' => (int) (clone $s)->where('module_key', 'sales')->sum('amount'),
        ]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => [
            'inflow' => (int) (clone $s)->where('module_key', 'sales')->sum('amount'),
            'outflow' => (int) (clone $s)->whereIn('module_key', ['expenses', 'purchases'])->sum('amount'),
        ]);
    }

    public function catalog(): JsonResponse
    {
        return $this->listResponse([
            ['key' => 'pl', 'labelAr' => 'الأرباح والخسائر', 'labelEn' => 'Profit & Loss'],
            ['key' => 'sales-channel', 'labelAr' => 'المبيعات حسب القناة', 'labelEn' => 'Sales by Channel'],
            ['key' => 'smart-compare', 'labelAr' => 'المقارنة الذكية', 'labelEn' => 'Smart Compare'],
            ['key' => 'profit-cash', 'labelAr' => 'الربح والنقدية', 'labelEn' => 'Profit & Cash'],
            ['key' => 'breakeven', 'labelAr' => 'نقطة التعادل', 'labelEn' => 'Breakeven'],
            ['key' => 'op-profit', 'labelAr' => 'ربح التشغيل', 'labelEn' => 'Operating Profit'],
            ['key' => 'menu-eng', 'labelAr' => 'هندسة القائمة', 'labelEn' => 'Menu Engineering'],
        ]);
    }

    public function generate(Request $request, \Modules\Admin\Services\ReportService $reports): JsonResponse
    {
        return $this->run(function () use ($request, $reports) {
            $data = $request->validate([
                'reportKey' => 'required|string|in:pl,sales-channel,smart-compare,profit-cash,breakeven,op-profit,menu-eng',
                'period' => 'sometimes|array',
                'period.from' => 'sometimes|date',
                'period.to' => 'sometimes|date',
                'brandIds' => 'sometimes|array',
                'restaurantIds' => 'sometimes|array',
                'branchIds' => 'sometimes|array',
                'format' => 'sometimes|in:json,pdf,xlsx',
            ]);

            $report = $reports->build($data);

            return $this->ok(array_merge([
                'reportId' => 'rpt_'.Str::upper(Str::random(10)),
                'generatedAt' => now()->toIso8601String(),
                'format' => $data['format'] ?? 'json',
                'downloadUrl' => null, // file export deferred to async exporter; json payload is inline
            ], $report));
        });
    }

    private function report(Request $request, callable $compute): JsonResponse
    {
        return $this->run(function () use ($request, $compute) {
            $scope = Operation::query();
            if ($from = $request->input('from')) {
                $scope->where('operation_date', '>=', $from);
            }
            if ($to = $request->input('to')) {
                $scope->where('operation_date', '<=', $to);
            }
            if ($branchIds = $request->input('branchIds')) {
                $scope->whereIn('branch_id', (array) $branchIds);
            }

            return $this->ok([
                'reportId' => 'rpt_'.Str::upper(Str::random(10)),
                'generatedAt' => now()->toIso8601String(),
                'data' => $compute($scope),
                'downloadUrl' => null,
            ]);
        });
    }
}
