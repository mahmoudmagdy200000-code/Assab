<?php

namespace Modules\Admin\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\OperationEnums;
use Modules\Branch\Models\Branch;

/**
 * §5.2c — the per-branch/day rollup state machine.
 *
 * A branch-day aggregates every operation dated that day into one accounting
 * state: لا بيانات → غير مكتمل → جاهز للتجميع → مُجمَّع → جاهز لـ ERP → مُصدَّر.
 * The state is always derived (never stored) so it can never drift from the
 * operations it summarises. `erp_imported` is reserved for the future stage
 * where the ERP confirms receipt and is therefore never derived here.
 */
class DailyRollupService
{
    /**
     * Derive one branch-day state from its status tallies.
     *
     * Rejected operations are off-pipeline: a day holding nothing but
     * rejections is `incomplete` (the branch must re-upload), not `empty`.
     *
     * @param  array{total:int, pending:int, approved:int, finalApproved:int, rejected:int, erpPosted:int}  $counts
     */
    public function deriveState(array $counts): string
    {
        if ($counts['total'] === 0) {
            return 'empty';
        }
        if ($counts['pending'] > 0) {
            return 'incomplete';
        }

        $live = $counts['approved'] + $counts['finalApproved'];
        if ($live === 0) {
            // Only rejected rows survive: the day still owes data.
            return 'incomplete';
        }
        if ($counts['approved'] > 0) {
            return $counts['finalApproved'] > 0 ? 'consolidated' : 'ready_consolidation';
        }

        return $counts['erpPosted'] >= $counts['finalApproved'] ? 'exported' : 'ready_erp';
    }

    /**
     * Branch-day rows for the dashboard's state chips.
     *
     * A single `date` returns one row per in-scope branch (including branches
     * with no operations, as `empty`). A `dateFrom`/`dateTo` range returns only
     * the branch-days that actually carry operations.
     *
     * @param  string  $companyId  the tenant to report on (admin may target another company)
     * @param  string[]|null  $assignedBranchIds  null = unrestricted (admin / company-wide role)
     * @param  array{date?:?string, dateFrom?:?string, dateTo?:?string, branchId?:?string, brandId?:?string}  $filters
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function rollup(string $companyId, ?array $assignedBranchIds, array $filters): array
    {
        $isRange = ! empty($filters['dateFrom']) || ! empty($filters['dateTo']);
        $from = Carbon::parse($filters['dateFrom'] ?? $filters['date'] ?? 'today')->startOfDay();
        $to = Carbon::parse($filters['dateTo'] ?? $filters['date'] ?? 'today')->endOfDay();

        $branches = $this->branchesInScope($companyId, $assignedBranchIds, $filters);
        $branchIds = array_keys($branches);

        $tallies = $branchIds === []
            ? []
            : $this->tallies($companyId, $branchIds, $from, $to);

        $rows = [];
        if ($isRange) {
            foreach ($tallies as $branchId => $byDate) {
                foreach ($byDate as $date => $counts) {
                    $rows[] = $this->row($branchId, $branches[$branchId] ?? null, $date, $counts);
                }
            }
        } else {
            $date = $from->toDateString();
            foreach ($branches as $branchId => $name) {
                $rows[] = $this->row($branchId, $name, $date, $tallies[$branchId][$date] ?? $this->emptyCounts());
            }
        }

        usort($rows, fn ($a, $b) => [$a['date'], $a['branchName']] <=> [$b['date'], $b['branchName']]);

        return ['rows' => $rows, 'summary' => $this->summary($rows, $from, $to)];
    }

    /** @return array<string, string> branchId => name */
    private function branchesInScope(string $companyId, ?array $assignedBranchIds, array $filters): array
    {
        $q = Branch::query()->select(['id', 'name'])->where('asab_company_id', $companyId);
        if ($assignedBranchIds !== null) {
            $q->whereIn('id', $assignedBranchIds);
        }
        if (! empty($filters['branchId'])) {
            $q->where('id', $filters['branchId']);
        }
        if (! empty($filters['brandId'])) {
            $q->where('asab_brand_id', $filters['brandId']);
        }

        return $q->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Status tallies keyed [branchId][Y-m-d].
     *
     * @param  string[]  $branchIds
     * @return array<string, array<string, array<string, int>>>
     */
    private function tallies(string $companyId, array $branchIds, Carbon $from, Carbon $to): array
    {
        $rows = $this->scoped($companyId)
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('operation_date', [$from, $to])
            ->selectRaw('branch_id, DATE(operation_date) as day, status, COUNT(*) as c, SUM(amount) as amt, SUM(CASE WHEN erp_posted = 1 THEN 1 ELSE 0 END) as erp')
            ->groupBy('branch_id', 'day', 'status')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $day = substr((string) $row->day, 0, 10);
            $counts = $out[$row->branch_id][$day] ?? $this->emptyCounts();

            $counts['total'] += (int) $row->c;
            $counts['erpPosted'] += (int) $row->erp;
            $counts['totalAmountHalalas'] += (int) $row->amt;
            $counts[$this->countKey($row->status)] += (int) $row->c;

            $out[$row->branch_id][$day] = $counts;
        }

        return $out;
    }

    private function countKey(string $status): string
    {
        return match ($status) {
            Operation::STATUS_PENDING => 'pending',
            Operation::STATUS_APPROVED => 'approved',
            Operation::STATUS_FINAL => 'finalApproved',
            default => 'rejected',
        };
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return ['total' => 0, 'pending' => 0, 'approved' => 0, 'finalApproved' => 0, 'rejected' => 0, 'erpPosted' => 0, 'totalAmountHalalas' => 0];
    }

    /** @param  array<string, int>  $counts */
    private function row(string $branchId, ?string $branchName, string $date, array $counts): array
    {
        return [
            'branchId' => $branchId,
            'branchName' => $branchName ?? '—',
            'date' => $date,
            'state' => OperationEnums::rollup($this->deriveState($counts)),
            'counts' => [
                'total' => $counts['total'],
                'pending' => $counts['pending'],
                'approved' => $counts['approved'],
                'finalApproved' => $counts['finalApproved'],
                'rejected' => $counts['rejected'],
                'erpPosted' => $counts['erpPosted'],
            ],
            'totalAmountHalalas' => $counts['totalAmountHalalas'],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function summary(array $rows, Carbon $from, Carbon $to): array
    {
        $byState = array_fill_keys(array_keys(OperationEnums::ROLLUP), 0);
        foreach ($rows as $row) {
            $byState[$row['state']['key']]++;
        }

        return [
            'dateFrom' => $from->toDateString(),
            'dateTo' => $to->toDateString(),
            'branchDays' => count($rows),
            'byState' => $byState,
        ];
    }

    /**
     * The tenant global scope already pins company users to their own company;
     * an admin carries no scope, so the company_id filter is always explicit.
     */
    private function scoped(string $companyId): Builder
    {
        return Operation::query()->where('company_id', $companyId);
    }
}
