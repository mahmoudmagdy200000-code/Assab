<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;
use Modules\Branch\Models\Branch;

/**
 * Assembles the full branch-manager landing screen (BRM-1): the monthly hero
 * (target vs actual), day/month KPIs, the tri-state «مهام اليوم» list and the
 * «طاقم اليوم» crew — from today's operations, the month's operations, active
 * employees and the branch's shift. All queries are branch-scoped.
 */
class BranchOverviewService
{
    /** Tri-state task labels (BRM-1.2). */
    public const TASK_STATE_LABELS = [
        'completed' => 'مكتمل',
        'pending' => 'معلق',
        'later' => 'لاحقاً',
    ];

    public function build(?string $branchId, ?string $companyId): array
    {
        $monthStart = now()->startOfMonth();

        $branch = $branchId
            ? Branch::whereKey($branchId)->when($companyId, fn ($q, $c) => $q->where('asab_company_id', $c))->first()
            : null;

        $todaySales = $this->sum($branchId, 'sales', today());
        $yesterdaySales = $this->sum($branchId, 'sales', today()->subDay());
        $monthSales = $this->sumSince($branchId, 'sales', $monthStart);
        $monthExpenses = $this->sumSince($branchId, 'expenses', $monthStart);
        $target = (int) ($branch->asab_monthly_target ?? 0);

        return [
            'branch' => ['id' => $branchId, 'name' => $branch->name ?? null],
            'hero' => [
                'targetHalalas' => $target,
                'actualHalalas' => $monthSales,
                'achievementPct' => $target > 0 ? (int) round($monthSales / $target * 100) : 0,
            ],
            'kpis' => [
                'todaySales' => $todaySales,
                'todaySalesTrendPct' => $this->trend($todaySales, $yesterdaySales),
                'todayOrders' => Operation::where('branch_id', $branchId)->whereDate('operation_date', today())->count(),
                'monthSales' => $monthSales,
                'monthExpenses' => $monthExpenses,
                'netProfit' => $monthSales - $monthExpenses,
                'activeEmployees' => Employee::where('branch_id', $branchId)->where('status', 'active')->count(),
                'requiredReportsCount' => 6,
            ],
            'tasksOfDay' => $this->tasksOfDay($branchId, $companyId),
            'crew' => $this->crew($branchId),
            'requiredReports' => $this->requiredReports($branchId),
        ];
    }

    /** @return array<int, array{id:string, label:string, state:string, stateLabel:string}> */
    private function tasksOfDay(?string $branchId, ?string $companyId): array
    {
        $uploaded = fn (string $module) => Operation::where('branch_id', $branchId)
            ->where('module_key', $module)->whereDate('operation_date', today())->exists();
        $inventoryCounted = Operation::where('branch_id', $branchId)->where('module_key', 'inventory')
            ->where('payload->countType', 'daily')->whereDate('operation_date', today())->exists();

        $activeShift = Shift::where('company_id', $companyId)->where('branch_id', $branchId)
            ->whereIn('status', ['active', 'late'])->exists();
        $shiftStartedToday = Shift::where('company_id', $companyId)->where('branch_id', $branchId)
            ->whereDate('started_at', today())->exists();
        $closeState = $activeShift ? 'pending' : ($shiftStartedToday ? 'completed' : 'later');

        $tasks = [
            ['id' => 'upload-morning-sales', 'label' => 'رفع مبيعات اليوم', 'state' => $uploaded('sales') ? 'completed' : 'pending'],
            ['id' => 'upload-expenses', 'label' => 'رفع المصروفات', 'state' => $uploaded('expenses') ? 'completed' : 'pending'],
            ['id' => 'daily-inventory-count', 'label' => 'جرد المخزون اليومي', 'state' => $inventoryCounted ? 'completed' : 'pending'],
            ['id' => 'close-evening-shift', 'label' => 'إغلاق الوردية المسائية', 'state' => $closeState],
        ];

        return array_map(fn ($t) => $t + ['stateLabel' => self::TASK_STATE_LABELS[$t['state']]], $tasks);
    }

    /** @return array<int, array<string, string|null>> */
    private function crew(?string $branchId): array
    {
        // No attendance table yet — active employees on the branch are the crew,
        // marked present. (Attendance is a future data source; SRS BRM-1.3.)
        return Employee::where('branch_id', $branchId)->where('status', 'active')
            ->orderBy('name')->limit(200)->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'role' => $e->role,
                'shift' => $e->shift_type,
                'attendanceStatus' => 'present',
                'attendanceLabel' => 'حاضر',
            ])->all();
    }

    private function requiredReports(?string $branchId): array
    {
        $types = [
            ['id' => 'sales', 'name' => 'تقرير المبيعات', 'module' => 'sales'],
            ['id' => 'inventory', 'name' => 'جرد المخزون اليومي', 'module' => 'inventory'],
            ['id' => 'cash', 'name' => 'تقرير النقدية', 'module' => 'cash'],
            ['id' => 'waste', 'name' => 'تقرير الهدر', 'module' => 'waste'],
            ['id' => 'purchases', 'name' => 'المشتريات', 'module' => 'purchases'],
            ['id' => 'expenses', 'name' => 'المصروفات', 'module' => 'expenses'],
        ];

        return array_map(function ($t) use ($branchId) {
            $uploaded = Operation::where('branch_id', $branchId)->where('module_key', $t['module'])
                ->whereDate('operation_date', today())->exists();

            return [
                'id' => $t['id'], 'name' => $t['name'], 'required' => true,
                'uploadedToday' => $uploaded, 'lastStatus' => $uploaded ? 'success' : 'missing',
            ];
        }, $types);
    }

    private function sum(?string $branchId, string $module, \Illuminate\Support\Carbon $date): int
    {
        return (int) Operation::where('branch_id', $branchId)->where('module_key', $module)
            ->whereDate('operation_date', $date)->sum('amount');
    }

    private function sumSince(?string $branchId, string $module, \Illuminate\Support\Carbon $since): int
    {
        return (int) Operation::where('branch_id', $branchId)->where('module_key', $module)
            ->where('operation_date', '>=', $since)->sum('amount');
    }

    private function trend(int $current, int $previous): float
    {
        if ($previous > 0) {
            return round(($current - $previous) / $previous * 100, 2);
        }

        return $current > 0 ? 100.0 : 0.0;
    }
}
