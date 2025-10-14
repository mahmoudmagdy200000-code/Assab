<?php

namespace Modules\BranchManagers\Services;

use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\CashierShift;
use Carbon\Carbon;

class DashboardService
{
    /**
     * Get complete dashboard data
     */
    public function getDashboardData(BranchManager $manager): array
    {
        return [
            'manager' => [
                'id' => $manager->id,
                'name' => $manager->name,
                'email' => $manager->email,
                'image' => $manager->image_url,
            ],
            'branch' => [
                'id' => $manager->branch->id,
                'name' => $manager->branch->name,
                'location' => $manager->branch->location,
                'opening_hours' => $manager->branch->opening_hours,
            ],
            'today_summary' => $this->getTodaySummary($manager),
            'quick_stats' => $this->getQuickStats($manager),
            'recent_activities' => $this->getRecentActivities($manager),
        ];
    }

    /**
     * Get today's summary
     */
    public function getTodaySummary(BranchManager $manager): array
    {
        $today = today();

        $shifts = CashierShift::whereHas('shift', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            })
            ->whereDate('shift_date', $today)
            ->get();

        $ordersInProgress = $shifts->where('status', 'in_progress')->count();

        $todayReceipts = $shifts->where('status', 'completed')
            ->sum('total_sales');

        return [
            'date' => $today->format('Y-m-d'),
            'day_name' => $today->format('l'),
            'orders_in_progress' => $ordersInProgress,
            'today_receipts' => (float) $todayReceipts,
            'total_shifts' => $shifts->count(),
            'completed_shifts' => $shifts->where('status', 'completed')->count(),
        ];
    }

    /**
     * Get quick statistics
     */
    public function getQuickStats(BranchManager $manager): array
    {
        return [
            'cashiers' => [
                'total' => $manager->getTotalCashiers(),
                'active' => $manager->getActiveCashiers(),
                'pending' => $manager->cashiers()->where('status', 'pending')->count(),
            ],
            'shifts' => [
                'today' => $manager->getTodayShifts(),
                'in_progress' => CashierShift::whereHas('shift', function ($q) use ($manager) {
                        $q->where('branch_id', $manager->branch_id);
                    })
                    ->where('status', 'in_progress')
                    ->whereDate('shift_date', today())
                    ->count(),
                'pending' => CashierShift::whereHas('shift', function ($q) use ($manager) {
                        $q->where('branch_id', $manager->branch_id);
                    })
                    ->where('status', 'not_started')
                    ->whereDate('shift_date', today())
                    ->count(),
            ],
            'sales' => [
                'today' => $this->getTodaySales($manager),
                'this_week' => $this->getWeekSales($manager),
                'this_month' => $this->getMonthSales($manager),
            ],
            'variance' => [
                'today' => $this->getTodayVariance($manager),
                'this_week' => $this->getWeekVariance($manager),
                'this_month' => $this->getMonthVariance($manager),
            ],
        ];
    }

    /**
     * Get recent activities
     */
    public function getRecentActivities(BranchManager $manager): array
    {
        $activities = [];

        // Recent cashiers added
        $recentCashiers = $manager->cashiers()
            ->latest()
            ->take(5)
            ->get();

        foreach ($recentCashiers as $cashier) {
            $activities[] = [
                'type' => 'cashier_added',
                'title' => 'New Cashier Added',
                'description' => "Added {$cashier->name} as cashier",
                'timestamp' => $cashier->created_at->diffForHumans(),
                'icon' => 'user-plus',
                'color' => 'green',
            ];
        }

        // Recent shifts with variance
        $varianceShifts = CashierShift::whereHas('shift', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            })
            ->where('status', 'completed')
            ->where('variance', '!=', 0)
            ->latest()
            ->take(5)
            ->get();

        foreach ($varianceShifts as $shift) {
            $activities[] = [
                'type' => 'variance_detected',
                'title' => 'Variance Detected',
                'description' => "Variance of " . abs($shift->variance) . " SAR in shift",
                'timestamp' => $shift->updated_at->diffForHumans(),
                'icon' => 'alert-triangle',
                'color' => 'red',
            ];
        }

        // Sort by timestamp
        usort($activities, function ($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });

        return array_slice($activities, 0, 10);
    }

    private function getTodaySales(BranchManager $manager): float
    {
        return CashierShift::whereHas('shift', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            })
            ->whereDate('shift_date', today())
            ->where('status', 'completed')
            ->sum('total_sales');
    }

    private function getWeekSales(BranchManager $manager): float
    {
        return CashierShift::whereHas('shift', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            })
            ->whereBetween('shift_date', [now()->startOfWeek(), now()->endOfWeek()])
            ->where('status', 'completed')
            ->sum('total_sales');
    }

    private function getMonthSales(BranchManager $manager): float
    {
        return CashierShift::whereHas('shift', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            })
            ->whereMonth('shift_date', now()->month)
            ->whereYear('shift_date', now()->year)
            ->where('status', 'completed')
            ->sum('total_sales');
    }

    private function getTodayVariance(BranchManager $manager): float
    {
        return CashierShift::whereHas('shift', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            })
            ->whereDate('shift_date', today())
            ->where('status', 'completed')
            ->sum('variance');
    }

    private function getWeekVariance(BranchManager $manager): float
    {
        return CashierShift::whereHas('shift', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            })
            ->whereBetween('shift_date', [now()->startOfWeek(), now()->endOfWeek()])
            ->where('status', 'completed')
            ->sum('variance');
    }

    private function getMonthVariance(BranchManager $manager): float
    {
        return CashierShift::whereHas('shift', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            })
            ->whereMonth('shift_date', now()->month)
            ->whereYear('shift_date', now()->year)
            ->where('status', 'completed')
            ->sum('variance');
    }
}
