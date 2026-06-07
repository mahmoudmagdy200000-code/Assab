<?php

namespace Modules\Admin\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;

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

        return collect($days)->map(fn ($d, $i) => [
            'day' => $d['day'],
            'dayAr' => $d['dayAr'],
            'thisWeek' => $thisWeek[$i] ?? 0,
            'lastWeek' => $lastWeek[$i] ?? 0,
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
        $queueDepth = (int) $this->scoped($companyId)->where('status', 'pending')->count();

        return $accountants->map(function (AsabUser $a) use ($companyId, $from, $to, $lastMonthStart, $lastMonthEnd, $queueDepth) {
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

            [$level, $levelLabelAr] = $this->level($rate);

            return [
                'id' => $a->id,
                'name' => $a->name,
                'branchesAssignedCount' => $this->branchesAssignedCount($a),
                'reviewedCount' => $reviewed,
                'approvedCount' => $approved,
                'pendingCount' => $queueDepth,
                'approvalRatePct' => $rate,
                'previousMonthRatePct' => $prevRate,
                'rating' => round(min(5, $rate / 20), 1),
                'avgReviewMinutes' => $avgReviewMinutes,
                'level' => $level,
                'levelLabelAr' => $levelLabelAr,
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

    private function branchesAssignedCount(AsabUser $a): int
    {
        $assignment = $a->roleAssignments->firstWhere('role_key', 'accountant');
        $ids = $assignment?->branch_ids ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }

        return is_array($ids) ? count($ids) : 0;
    }

    private function level(int $rate): array
    {
        return match (true) {
            $rate >= 90 => ['excellent', 'ممتاز'],
            $rate >= 75 => ['good', 'جيد'],
            $rate >= 60 => ['acceptable', 'مقبول'],
            default => ['needs_improvement', 'يحتاج تحسين'],
        };
    }

    /** Explicit company scope (bypasses the tenant global scope for determinism). */
    private function scoped(string $companyId): Builder
    {
        return Operation::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
