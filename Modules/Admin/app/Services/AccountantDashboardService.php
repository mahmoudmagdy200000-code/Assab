<?php

namespace Modules\Admin\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\ModuleCatalog;
use Modules\Admin\Support\TenantContext;
use Modules\Branch\Models\Branch;

/**
 * SRS ACC-0 — «ملخص اليوم». The KPI block, the nine-module grid, today's
 * progress bars and the accountant's own scope subtitle, shared by the platform
 * and company-portal dashboards so the two can never drift.
 *
 * Everything is computed inside the caller's assigned-branch scope: an
 * accountant assigned to branches 1–50 must never count branch 51.
 */
class AccountantDashboardService
{
    /** A pending operation older than this is «urgent» on the module grid. */
    private const URGENT_AFTER_HOURS = 48;

    /**
     * @param  string[]|null  $branchIds  null = company-wide (head / admin)
     * @return array<string, mixed>
     */
    public function kpis(AsabUser $actor, ?array $branchIds): array
    {
        $base = fn (): Builder => $this->scoped($actor, $branchIds);

        $byStatus = $base()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $pending = (int) ($byStatus[Operation::STATUS_PENDING] ?? 0);
        $approved = (int) ($byStatus[Operation::STATUS_APPROVED] ?? 0);
        $final = (int) ($byStatus[Operation::STATUS_FINAL] ?? 0);
        $rejected = (int) ($byStatus[Operation::STATUS_REJECTED] ?? 0);

        // «معدل الموافقة» is the actor's own record: how many of the operations
        // they closed were approved rather than rejected.
        $approvedByMe = (int) $base()->where('approved_by_id', $actor->id)->count();
        $rejectedByMe = (int) $base()->where('rejected_by_id', $actor->id)->count();
        $reviewedByMe = $approvedByMe + $rejectedByMe;

        return [
            'awaitingReview' => $pending,
            'iApproved' => $approvedByMe,
            'finalApproved' => $final,
            'rejected' => $rejected,
            'newTodayCount' => (int) $base()->where('submitted_at', '>=', now()->startOfDay())->count(),
            'approvalRatePct' => $reviewedByMe === 0 ? 0 : (int) round($approvedByMe / $reviewedByMe * 100),
            'overdueCount' => (int) $base()->where('status', Operation::STATUS_PENDING)
                ->where('submitted_at', '<', now()->subDays(2))->count(),
            // Kept for the pre-T04 readers of the platform dashboard.
            'approvalRate' => $reviewedByMe === 0 ? 0 : (int) round($approvedByMe / $reviewedByMe * 100),
            'pendingApprovedTotal' => $approved,
        ];
    }

    /**
     * The nine-module grid: pending/total per module plus the urgent red dot
     * («فرق في الكمية» or a pending record older than 48h).
     *
     * @param  string[]|null  $branchIds
     * @return array<int, array<string, mixed>>
     */
    public function moduleGrid(AsabUser $actor, ?array $branchIds): array
    {
        $rows = $this->scoped($actor, $branchIds)
            ->selectRaw('module_key, status, `match` as match_status, count(*) as c, min(submitted_at) as oldest')
            ->groupBy('module_key', 'status', 'match_status')
            ->get();

        $urgentBefore = now()->subHours(self::URGENT_AFTER_HOURS);
        $grid = [];
        foreach (ModuleCatalog::MODULES as $key => $meta) {
            $grid[$key] = [
                'key' => $key,
                'labelAr' => $meta['labelAr'],
                'icon' => $meta['icon'],
                'pendingCount' => 0,
                'totalCount' => 0,
                'hasUrgent' => false,
                // Legacy key kept so the old platform dashboard keeps rendering.
                'label' => $meta['labelAr'],
            ];
        }

        foreach ($rows as $row) {
            if (! isset($grid[$row->module_key])) {
                continue;
            }
            $count = (int) $row->c;
            $grid[$row->module_key]['totalCount'] += $count;

            if ($row->status !== Operation::STATUS_PENDING) {
                continue;
            }
            $grid[$row->module_key]['pendingCount'] += $count;

            $isStale = $row->oldest !== null && $row->oldest < $urgentBefore->toDateTimeString();
            if ($row->match_status === 'diff' || $isStale) {
                $grid[$row->module_key]['hasUrgent'] = true;
            }
        }

        return array_values($grid);
    }

    /**
     * ACC-0.6 «تقدم اليوم» — how far today's uploads have travelled.
     *
     * @param  string[]|null  $branchIds
     * @return array<string, int>
     */
    public function progressToday(AsabUser $actor, ?array $branchIds): array
    {
        $today = $this->scoped($actor, $branchIds)->whereDate('operation_date', now()->toDateString());

        $total = (int) (clone $today)->count();
        $pct = fn (int $n) => $total === 0 ? 0 : (int) round($n / $total * 100);

        $reviewed = (int) (clone $today)->where('status', '!=', Operation::STATUS_PENDING)->count();
        $approved = (int) (clone $today)->whereIn('status', [Operation::STATUS_APPROVED, Operation::STATUS_FINAL])->count();
        $documented = (int) (clone $today)->where('attachment_count', '>', 0)->count();

        $branchesWithUploads = (int) (clone $today)->distinct()->count('branch_id');
        $branchesInScope = $this->branchCount($actor, $branchIds);

        return [
            'reviewPct' => $pct($reviewed),
            'approvalPct' => $pct($approved),
            'documentationPct' => $pct($documented),
            'completedBranchesPct' => $branchesInScope === 0 ? 0 : (int) round($branchesWithUploads / $branchesInScope * 100),
            'operationsToday' => $total,
        ];
    }

    /**
     * ACC-0.1 — the subtitle «الفروع المخصصة: … · الموديولات: …».
     *
     * @param  string[]|null  $branchIds
     * @return array<string, mixed>
     */
    public function scope(AsabUser $actor, ?array $branchIds, TenantContext $ctx): array
    {
        $moduleKeys = $ctx->moduleKeys !== [] ? $ctx->moduleKeys : ModuleCatalog::keys();

        return [
            'branchCount' => $this->branchCount($actor, $branchIds),
            'branchIds' => $branchIds,
            'isCompanyWide' => $branchIds === null,
            'moduleKeys' => array_values($moduleKeys),
            'moduleLabelsAr' => array_map(fn ($k) => ModuleCatalog::labelAr($k), array_values($moduleKeys)),
        ];
    }

    /** @param  string[]|null  $branchIds */
    private function branchCount(AsabUser $actor, ?array $branchIds): int
    {
        if ($branchIds !== null) {
            return count($branchIds);
        }

        return (int) Branch::where('asab_company_id', $actor->company_id)->count();
    }

    /** @param  string[]|null  $branchIds */
    private function scoped(AsabUser $actor, ?array $branchIds): Builder
    {
        $q = Operation::query()->where('company_id', $actor->company_id);
        if ($branchIds !== null) {
            $q->whereIn('branch_id', $branchIds);
        }

        return $q;
    }
}
