<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;

/**
 * SRS ACC-1.2 — the day pills and their banner
 * «n عملية مطلوبة — m مكتملة · k ناقصة».
 *
 * Every in-scope branch owes one sales statement per day. "Required" is
 * therefore the branch count, "completed" the branches that uploaded, and the
 * remainder is what the accountant must chase.
 */
class SalesCompletenessService
{
    private const MODULE = 'sales';

    /**
     * @param  string[]|null  $branchIds  null = every branch of the company
     * @return array<int, array<string, mixed>> newest day first
     */
    public function days(string $companyId, ?array $branchIds, int $days = 7): array
    {
        $days = max(1, min($days, 31));
        $branches = $this->branches($companyId, $branchIds);
        $required = count($branches);

        $from = now()->subDays($days - 1)->startOfDay();
        $uploads = Operation::query()
            ->where('company_id', $companyId)
            ->where('module_key', self::MODULE)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->where('operation_date', '>=', $from)
            ->selectRaw('branch_id, DATE(operation_date) as day')
            ->distinct()
            ->get()
            ->groupBy(fn ($row) => substr((string) $row->day, 0, 10))
            ->map(fn ($rows) => $rows->pluck('branch_id')->filter()->unique()->all());

        $rows = [];
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i)->startOfDay();
            $key = $date->toDateString();
            $completedIds = $uploads[$key] ?? [];
            $missing = array_diff(array_keys($branches), $completedIds);

            $rows[] = [
                'date' => $key,
                'pillLabelAr' => $this->pillLabel($date, $i),
                'requiredCount' => $required,
                'completedCount' => count($completedIds),
                'missingCount' => count($missing),
                'bannerAr' => sprintf(
                    '%d عملية مطلوبة — %d مكتملة · %d ناقصة',
                    $required,
                    count($completedIds),
                    count($missing),
                ),
                'missingBranches' => array_values(array_map(
                    fn ($id) => ['branchId' => $id, 'name' => $branches[$id]],
                    $missing,
                )),
            ];
        }

        return $rows;
    }

    /** @return array<string, string> branchId => name */
    private function branches(string $companyId, ?array $branchIds): array
    {
        return Branch::query()
            ->where('asab_company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function pillLabel(Carbon $date, int $daysAgo): string
    {
        return match ($daysAgo) {
            0 => 'اليوم',
            1 => 'أمس',
            2 => 'قبل يومين',
            default => $daysAgo <= 7 ? 'قبل '.$daysAgo.' أيام' : $date->toDateString(),
        };
    }
}
