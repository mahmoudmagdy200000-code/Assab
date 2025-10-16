<?php

namespace Modules\Aggregator\Services;


use Modules\Aggregator\Models\Aggregator;

class AggregatorReportService
{
    /**
     * Get comprehensive sales report
     */
    public function getSalesReport(Aggregator $aggregator, string $period): array
    {
        $salesBreakdown = $aggregator->salesBreakdownForPeriod($period)
            ->with(['cashierShift.cashier', 'cashierShift.shift.branch'])
            ->get();

        $totalSales = $salesBreakdown->sum('amount');
        $totalOrders = $salesBreakdown->count();
        $averagePerOrder = $totalOrders > 0 ? ($totalSales / $totalOrders) : 0;

        // Group by branch
        $salesByBranch = $salesBreakdown->groupBy(function ($sale) {
            return $sale->cashierShift->shift->branch_id;
        })->map(function ($branchSales) {
            $branch = $branchSales->first()->cashierShift->shift->branch;
            return [
                'branch_name' => $branch->name,
                'total_sales' => $branchSales->sum('amount'),
                'total_orders' => $branchSales->count(),
            ];
        })->values();

        // Group by date
        $salesByDate = $salesBreakdown->groupBy(function ($sale) {
            return $sale->cashierShift->shift_date->format('Y-m-d');
        })->map(function ($dateSales, $date) {
            return [
                'date' => $date,
                'total_sales' => $dateSales->sum('amount'),
                'total_orders' => $dateSales->count(),
            ];
        })->values();

        return [
            'period' => $period,
            'summary' => [
                'total_sales' => (float) $totalSales,
                'total_orders' => $totalOrders,
                'average_per_order' => (float) $averagePerOrder,
            ],
            'sales_by_branch' => $salesByBranch,
            'sales_by_date' => $salesByDate,
        ];
    }

    /**
     * Get commission report
     */
    public function getCommissionReport(Aggregator $aggregator, string $period): array
    {
        $totalSales = $aggregator->getTotalSales($period);
        $commissionRate = $aggregator->commission_rate;
        $totalCommission = $totalSales * ($commissionRate / 100);

        // Get commission breakdown by branch
        $branchBreakdown = $aggregator->getSalesBreakdownByBranch($period);

        $commissionByBranch = collect($branchBreakdown)->map(function ($branch) use ($commissionRate) {
            return [
                'branch_name' => $branch['branch_name'],
                'total_sales' => $branch['total_sales'],
                'commission' => $branch['total_sales'] * ($commissionRate / 100),
            ];
        });

        return [
            'period' => $period,
            'commission_rate' => (float) $commissionRate,
            'summary' => [
                'total_sales' => (float) $totalSales,
                'total_commission' => (float) $totalCommission,
            ],
            'commission_by_branch' => $commissionByBranch,
        ];
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(Aggregator $aggregator, string $period): array
    {
        $currentSales = $aggregator->getTotalSales($period);
        $currentOrders = $aggregator->getTotalOrders($period);

        // Get previous period for comparison
        $previousPeriod = $this->getPreviousPeriod($period);
        $previousSales = $this->getSalesForPeriod($aggregator, $previousPeriod);
        $previousOrders = $this->getOrdersForPeriod($aggregator, $previousPeriod);

        // Calculate growth
        $salesGrowth = $previousSales > 0
            ? (($currentSales - $previousSales) / $previousSales) * 100
            : 0;

        $ordersGrowth = $previousOrders > 0
            ? (($currentOrders - $previousOrders) / $previousOrders) * 100
            : 0;

        return [
            'period' => $period,
            'current' => [
                'sales' => (float) $currentSales,
                'orders' => $currentOrders,
                'average_per_order' => $currentOrders > 0 ? ($currentSales / $currentOrders) : 0,
            ],
            'previous' => [
                'sales' => (float) $previousSales,
                'orders' => $previousOrders,
                'average_per_order' => $previousOrders > 0 ? ($previousSales / $previousOrders) : 0,
            ],
            'growth' => [
                'sales_percentage' => (float) number_format($salesGrowth, 2),
                'orders_percentage' => (float) number_format($ordersGrowth, 2),
                'sales_amount' => (float) ($currentSales - $previousSales),
                'orders_count' => ($currentOrders - $previousOrders),
            ],
        ];
    }

    /**
     * Get previous period dates
     */
    private function getPreviousPeriod(string $period): array
    {
        return match($period) {
            'today' => [
                'start' => now()->subDay()->startOfDay(),
                'end' => now()->subDay()->endOfDay(),
            ],
            'week' => [
                'start' => now()->subWeek()->startOfWeek(),
                'end' => now()->subWeek()->endOfWeek(),
            ],
            'month' => [
                'start' => now()->subMonth()->startOfMonth(),
                'end' => now()->subMonth()->endOfMonth(),
            ],
            'year' => [
                'start' => now()->subYear()->startOfYear(),
                'end' => now()->subYear()->endOfYear(),
            ],
            default => [
                'start' => now()->subMonth()->startOfMonth(),
                'end' => now()->subMonth()->endOfMonth(),
            ],
        };
    }

    /**
     * Get sales for specific date range
     */
    private function getSalesForPeriod(Aggregator $aggregator, array $period): float
    {
        return $aggregator->salesBreakdown()
            ->whereHas('cashierShift', function ($q) use ($period) {
                $q->whereBetween('shift_date', [$period['start'], $period['end']]);
            })
            ->sum('amount');
    }

    /**
     * Get orders for specific date range
     */
    private function getOrdersForPeriod(Aggregator $aggregator, array $period): int
    {
        return $aggregator->salesBreakdown()
            ->whereHas('cashierShift', function ($q) use ($period) {
                $q->whereBetween('shift_date', [$period['start'], $period['end']]);
            })
            ->count();
    }
}

