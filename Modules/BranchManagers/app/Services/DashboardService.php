<?php

namespace Modules\BranchManagers\Services;

use Illuminate\Support\Collection;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\CashierShift;

class DashboardService
{
    /**
     * Get complete dashboard data
     */
    public function getDashboardData(BranchManager $manager): array
    {
        // Load today's branch shifts once and reuse for both summary and quick stats.
        $todayShifts = $this->getTodayBranchShifts($manager);

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
                'lat' => $manager->branch->lat ? (float) $manager->branch->lat : null,
                'lng' => $manager->branch->lng ? (float) $manager->branch->lng : null,
                'opening_hours' => $manager->branch->opening_hours,
            ],
            'today_summary' => $this->getTodaySummary($manager, $todayShifts),
            'quick_stats' => $this->getQuickStats($manager, $todayShifts),
            'recent_activities' => $this->getRecentActivities($manager),
        ];
    }

    /**
     * Get today's summary
     */
    public function getTodaySummary(BranchManager $manager, ?Collection $todayShifts = null): array
    {
        $today = today();
        $shifts = $todayShifts ?? $this->getTodayBranchShifts($manager);

        return [
            'date' => $today->format('Y-m-d'),
            'day_name' => $today->format('l'),
            'orders_in_progress' => $shifts->where('status', 'in_progress')->count(),
            'today_receipts' => (float) $shifts->where('status', 'completed')->sum('total_sales'),
            'total_shifts' => $shifts->count(),
            'completed_shifts' => $shifts->where('status', 'completed')->count(),
        ];
    }

    /**
     * Get quick statistics
     */
    public function getQuickStats(BranchManager $manager, ?Collection $todayShifts = null): array
    {
        $shifts = $todayShifts ?? $this->getTodayBranchShifts($manager);
        $completedToday = $shifts->where('status', 'completed');

        // One query for all cashier counts instead of three.
        $cashierCounts = $manager->cashiers()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COUNT(CASE WHEN status = 'active' THEN 1 END) as active")
            ->selectRaw("COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending")
            ->first();

        // One query per period for sales + variance instead of one each.
        $week = $this->getCompletedTotals($manager, function ($q) {
            $q->whereBetween('shift_date', [now()->startOfWeek(), now()->endOfWeek()]);
        });
        $month = $this->getCompletedTotals($manager, function ($q) {
            $q->whereMonth('shift_date', now()->month)
                ->whereYear('shift_date', now()->year);
        });

        return [
            'cashiers' => [
                'total' => (int) $cashierCounts->total,
                'active' => (int) $cashierCounts->active,
                'pending' => (int) $cashierCounts->pending,
            ],
            'shifts' => [
                'today' => $shifts->count(),
                'in_progress' => $shifts->where('status', 'in_progress')->count(),
                'pending' => $shifts->where('status', 'not_started')->count(),
            ],
            'sales' => [
                'today' => (float) $completedToday->sum('total_sales'),
                'this_week' => $week['sales'],
                'this_month' => $month['sales'],
            ],
            'variance' => [
                'today' => (float) $completedToday->sum('variance'),
                'this_week' => $week['variance'],
                'this_month' => $month['variance'],
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
        $varianceShifts = CashierShift::without(['cashier', 'shift', 'nextCashier'])
            ->whereHas('shift', function ($q) use ($manager) {
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
                'description' => 'Variance of '.abs($shift->variance).' SAR in shift',
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

    /**
     * Load today's cashier shifts for the manager's branch.
     * Relations are skipped because the dashboard only needs own columns.
     */
    private function getTodayBranchShifts(BranchManager $manager): Collection
    {
        return CashierShift::without(['cashier', 'shift', 'nextCashier'])
            ->whereHas('shift', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            })
            ->whereDate('shift_date', today())
            ->get();
    }

    /**
     * Sum sales and variance for completed shifts in the manager's branch
     * over a date range supplied by the caller.
     *
     * @return array{sales: float, variance: float}
     */
    private function getCompletedTotals(BranchManager $manager, callable $dateFilter): array
    {
        $query = CashierShift::without(['cashier', 'shift', 'nextCashier'])
            ->whereHas('shift', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            })
            ->where('status', 'completed');

        $dateFilter($query);

        $row = $query
            ->selectRaw('COALESCE(SUM(total_sales), 0) as sales, COALESCE(SUM(variance), 0) as variance')
            ->first();

        return [
            'sales' => (float) ($row->sales ?? 0),
            'variance' => (float) ($row->variance ?? 0),
        ];
    }
}
