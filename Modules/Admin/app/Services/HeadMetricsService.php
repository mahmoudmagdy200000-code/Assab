<?php

namespace Modules\Admin\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;

/**
 * Head Accountant dashboard metrics (MISSING_Dashboard §4). Shared by the
 * platform /head/* surface and the company /company/me/head/* surface so both
 * return identical KPI / weekly-performance / accountant-performance shapes.
 */
class HeadMetricsService
{
    private const MODULE_LABELS = [
        'sales' => 'مبيعات', 'expenses' => 'مصروفات', 'purchases' => 'مشتريات',
        'inventory' => 'مخزون', 'shifts' => 'ورديات', 'employees' => 'موظفين',
        'cash' => 'نقدية', 'waste' => 'هدر',
    ];

    /** §4.1 KPI block — this-month review throughput vs last month. */
    public function dashboardKpis(string $companyId): array
    {
        $monthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $monthStart->copy()->subSecond();

        $reviewed = (int) $this->scoped($companyId)->whereNotNull('reviewed_at')->where('reviewed_at', '>=', $monthStart)->count();
        $approved = (int) $this->scoped($companyId)->whereIn('status', ['approved', 'final-approved'])->where('reviewed_at', '>=', $monthStart)->count();
        $rejected = (int) $this->scoped($companyId)->where('status', 'rejected')->where('rejected_at', '>=', $monthStart)->count();
        $rate = $reviewed > 0 ? (int) round(($approved / $reviewed) * 100) : 0;

        $reviewedOps = $this->scoped($companyId)->whereNotNull('submitted_at')->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', $monthStart)->limit(2000)->get(['submitted_at', 'reviewed_at']);
        $avgReviewMinutes = $reviewedOps->isEmpty()
            ? 0.0
            : round($reviewedOps->avg(fn ($o) => $o->submitted_at->diffInMinutes($o->reviewed_at)), 2);

        $lastReviewed = (int) $this->scoped($companyId)->whereNotNull('reviewed_at')
            ->whereBetween('reviewed_at', [$lastMonthStart, $lastMonthEnd])->count();
        $delta = $lastReviewed > 0 ? round((($reviewed - $lastReviewed) / $lastReviewed) * 100, 1) : 0.0;

        return [
            'performanceRatePct' => $rate,
            'totalReviewedThisMonth' => $reviewed,
            'totalApprovedThisMonth' => $approved,
            'totalRejectedThisMonth' => $rejected,
            'avgReviewTimeMinutes' => $avgReviewMinutes,
            'vsLastMonthDeltaPct' => $delta,
        ];
    }

    /** HEAD-1.1 «المحاسبون النشطون n/n» — active vs total company accountants. */
    public function accountantsActive(string $companyId): array
    {
        $accountants = AsabUser::where('company_id', $companyId)
            ->whereHas('roleAssignments', fn ($r) => $r->where('role_key', 'accountant'))->get(['id', 'status']);

        return [
            'active' => $accountants->where('status', 'active')->count(),
            'total' => $accountants->count(),
        ];
    }

    /**
     * HEAD-1.3 brand performance — this-month sales/expenses/net per brand and
     * pctOfTarget vs the sum of the brand's branch monthly_target. Two grouped
     * queries (by branch) + a branch→brand map; bounded by branch count.
     *
     * @return array<int, array<string,mixed>>
     */
    public function brandPerformance(string $companyId): array
    {
        $brands = AsabBrand::where('company_id', $companyId)->get(['id', 'name', 'abbr', 'color']);
        if ($brands->isEmpty()) {
            return [];
        }
        $branches = Branch::where('asab_company_id', $companyId)->get(['id', 'asab_brand_id', 'asab_monthly_target']);
        $branchToBrand = $branches->pluck('asab_brand_id', 'id');
        $targetByBrand = $branches->groupBy('asab_brand_id')->map(fn ($g) => (int) $g->sum('asab_monthly_target'));

        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $sumByBranch = fn (string $module) => $this->scoped($companyId)
            ->where('module_key', $module)->whereBetween('operation_date', [$monthStart, $monthEnd])
            ->selectRaw('branch_id, sum(amount) as a')->groupBy('branch_id')->pluck('a', 'branch_id');
        $salesByBranch = $sumByBranch('sales');
        $expensesByBranch = $sumByBranch('expenses');

        $agg = [];
        foreach ($branchToBrand as $branchId => $brandId) {
            $agg[$brandId] ??= ['sales' => 0, 'expenses' => 0];
            $agg[$brandId]['sales'] += (int) ($salesByBranch[$branchId] ?? 0);
            $agg[$brandId]['expenses'] += (int) ($expensesByBranch[$branchId] ?? 0);
        }

        return $brands->map(function (AsabBrand $b) use ($agg, $targetByBrand) {
            $sales = (int) ($agg[$b->id]['sales'] ?? 0);
            $expenses = (int) ($agg[$b->id]['expenses'] ?? 0);
            $target = (int) ($targetByBrand[$b->id] ?? 0);

            return [
                'brandId' => $b->id,
                'name' => $b->name,
                'abbr' => $b->abbr,
                'color' => $b->color,
                'salesHalalas' => $sales,
                'expensesHalalas' => $expenses,
                'netHalalas' => $sales - $expenses,
                'pctOfTarget' => $target > 0 ? round($sales / $target * 100, 1) : 0,
            ];
        })->all();
    }

    /** §4.1 weekly performance — reviewed counts per weekday, this vs last week. */
    public function weeklyPerformance(string $companyId): array
    {
        $startThis = now()->startOfWeek(CarbonInterface::SUNDAY);
        $startLast = $startThis->copy()->subWeek();

        $thisWeek = $this->dailyReviewedCounts($companyId, $startThis, $startThis->copy()->addWeek());
        $lastWeek = $this->dailyReviewedCounts($companyId, $startLast, $startThis->copy());

        $days = [
            ['day' => 'Sun', 'dayAr' => 'الأحد'], ['day' => 'Mon', 'dayAr' => 'الاثنين'],
            ['day' => 'Tue', 'dayAr' => 'الثلاثاء'], ['day' => 'Wed', 'dayAr' => 'الأربعاء'],
            ['day' => 'Thu', 'dayAr' => 'الخميس'], ['day' => 'Fri', 'dayAr' => 'الجمعة'],
            ['day' => 'Sat', 'dayAr' => 'السبت'],
        ];

        // `thisW`/`lastW` are FE-contract aliases (B-H2) for thisWeek/lastWeek.
        return collect($days)->map(fn ($d, $i) => [
            'day' => $d['day'],
            'dayAr' => $d['dayAr'],
            'thisWeek' => $thisWeek[$i] ?? 0,
            'lastWeek' => $lastWeek[$i] ?? 0,
            'thisW' => $thisWeek[$i] ?? 0,
            'lastW' => $lastWeek[$i] ?? 0,
        ])->all();
    }

    /** @return int[] indexed 0=Sunday..6=Saturday */
    private function dailyReviewedCounts(string $companyId, CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = $this->scoped($companyId)->whereNotNull('reviewed_at')
            ->whereBetween('reviewed_at', [$start, $end])->limit(20000)->get(['reviewed_at']);

        $counts = array_fill(0, 7, 0);
        foreach ($rows as $r) {
            $counts[(int) $r->reviewed_at->dayOfWeek]++;
        }

        return $counts;
    }

    /** §4.2 per-accountant performance cards. */
    public function accountantsPerformance(string $companyId, ?string $from, ?string $to): array
    {
        $accountants = AsabUser::where('company_id', $companyId)
            ->whereHas('roleAssignments', fn ($r) => $r->where('role_key', 'accountant'))
            ->with('roleAssignments')->get();

        $monthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $monthStart->copy()->subSecond();

        return $accountants->map(function (AsabUser $a) use ($companyId, $from, $to, $lastMonthStart, $lastMonthEnd) {
            $base = function () use ($companyId, $a, $from, $to): Builder {
                $q = $this->scoped($companyId)->where('approved_by_id', $a->id);
                if ($from) {
                    $q->whereDate('reviewed_at', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('reviewed_at', '<=', $to);
                }

                return $q;
            };

            $reviewed = (int) $base()->count();
            $approved = (int) $base()->whereIn('status', ['approved', 'final-approved'])->count();
            $rate = $reviewed > 0 ? (int) round(($approved / $reviewed) * 100) : 0;

            $prevReviewed = (int) $this->scoped($companyId)->where('approved_by_id', $a->id)
                ->whereBetween('reviewed_at', [$lastMonthStart, $lastMonthEnd])->count();
            $prevApproved = (int) $this->scoped($companyId)->where('approved_by_id', $a->id)
                ->whereIn('status', ['approved', 'final-approved'])
                ->whereBetween('reviewed_at', [$lastMonthStart, $lastMonthEnd])->count();
            $prevRate = $prevReviewed > 0 ? (int) round(($prevApproved / $prevReviewed) * 100) : 0;

            $reviewedOps = $this->scoped($companyId)->where('approved_by_id', $a->id)
                ->whereNotNull('submitted_at')->whereNotNull('reviewed_at')->limit(2000)->get(['submitted_at', 'reviewed_at']);
            $avgReviewMinutes = $reviewedOps->isEmpty()
                ? 0.0
                : round($reviewedOps->avg(fn ($o) => $o->submitted_at->diffInMinutes($o->reviewed_at)), 1);

            [$level, $levelLabelAr, $levelCls] = $this->level($rate);
            $branchIds = $this->accountantBranchIds($a);
            $pending = empty($branchIds)
                ? 0
                : (int) $this->scoped($companyId)->where('status', 'pending')->whereIn('branch_id', $branchIds)->count();
            $rating = round(min(5, $rate / 20), 1);

            // Canonical keys + FE-contract aliases (B-H3): rate/prevRate/avgTime/
            // reviewed/approved/pending/branches + levelCls + per-accountant movements.
            return [
                'id' => $a->id,
                'name' => $a->name,
                'branchesAssignedCount' => count($branchIds),
                'branches' => count($branchIds),
                'reviewedCount' => $reviewed,
                'reviewed' => $reviewed,
                'approvedCount' => $approved,
                'approved' => $approved,
                'pendingCount' => $pending,
                'pending' => $pending,
                'approvalRatePct' => $rate,
                'rate' => $rate,
                'previousMonthRatePct' => $prevRate,
                'prevRate' => $prevRate,
                'rating' => $rating,
                'avgReviewMinutes' => $avgReviewMinutes,
                'avgTime' => $avgReviewMinutes,
                'level' => $level,
                'levelLabelAr' => $levelLabelAr,
                'levelCls' => $levelCls,
                'recentMovements' => $this->accountantMovements($companyId, $a->id, 5),
            ];
        })->values()->all();
    }

    /** §4.3 recent movements feed (from the approval-step ledger). */
    public function recentMovements(string $companyId, int $limit): array
    {
        $steps = ApprovalStep::whereIn('operation_id', Operation::where('company_id', $companyId)->select('id'))
            ->orderByDesc('occurred_at')->limit($limit)->get();

        $modules = Operation::whereIn('id', $steps->pluck('operation_id')->unique()->values())
            ->get(['id', 'module_key'])->pluck('module_key', 'id');

        return $steps->map(function (ApprovalStep $s) use ($modules) {
            $module = $modules[$s->operation_id] ?? null;

            return [
                'id' => $s->id,
                'actionAr' => $s->note ?: $this->actionLabel($s->action, $module),
                'timeAr' => optional($s->occurred_at)->diffForHumans(),
                'module' => $module,
                'moduleLabelAr' => $module ? (self::MODULE_LABELS[$module] ?? $module) : null,
            ];
        })->all();
    }

    private function actionLabel(?string $action, ?string $module): string
    {
        $verb = match ($action) {
            'approve', 'approved' => 'اعتماد',
            'reject', 'rejected' => 'رفض',
            'final-approve', 'final_approved' => 'اعتماد نهائي',
            'correction' => 'تصحيح',
            default => 'تحديث',
        };

        return trim($verb.' '.($module ? (self::MODULE_LABELS[$module] ?? $module) : ''));
    }

    /** @return string[] branch ids assigned to the accountant's role. */
    private function accountantBranchIds(AsabUser $a): array
    {
        $assignment = $a->roleAssignments->firstWhere('role_key', 'accountant');
        $ids = $assignment?->branch_ids ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }

        return is_array($ids) ? array_values($ids) : [];
    }

    /** Recent approval-ledger movements performed BY one accountant (B-H3). */
    private function accountantMovements(string $companyId, string $accountantId, int $limit): array
    {
        $steps = ApprovalStep::where('actor_user_id', $accountantId)
            ->whereIn('operation_id', Operation::where('company_id', $companyId)->select('id'))
            ->orderByDesc('occurred_at')->limit($limit)->get();

        $modules = Operation::whereIn('id', $steps->pluck('operation_id')->unique()->values())
            ->get(['id', 'module_key'])->pluck('module_key', 'id');

        return $steps->map(function (ApprovalStep $s) use ($modules) {
            $module = $modules[$s->operation_id] ?? null;

            return [
                'id' => $s->id,
                'actionAr' => $s->note ?: $this->actionLabel($s->action, $module),
                'timeAr' => optional($s->occurred_at)->diffForHumans(),
                'module' => $module,
                'moduleLabelAr' => $module ? (self::MODULE_LABELS[$module] ?? $module) : null,
            ];
        })->all();
    }

    /** @return array{0:string,1:string,2:string} [levelKey, labelAr, cssToken] */
    private function level(int $rate): array
    {
        return match (true) {
            $rate >= 90 => ['excellent', 'ممتاز', 'emerald'],
            $rate >= 75 => ['good', 'جيد', 'blue'],
            $rate >= 60 => ['acceptable', 'مقبول', 'amber'],
            default => ['needs_improvement', 'يحتاج تحسين', 'red'],
        };
    }

    /** Explicit company scope (bypasses the tenant global scope for determinism). */
    private function scoped(string $companyId): Builder
    {
        return Operation::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
