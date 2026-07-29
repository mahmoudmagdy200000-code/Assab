<?php

namespace Modules\BrandOwner\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\Expense;

/**
 * Builds the Brand Owner Home dashboard payloads (BrandOwnerHomeScreen):
 *  - the branch list for the home branch selector
 *  - invoice / expense summaries for a branch + month
 *  - daily / weekly / monthly expense trend charts
 *
 * Spec: brand-owner-home-doc.md
 *
 * "Invoices" and "expenses" are both rows of the `expenses` table, split by
 * `expense_type` — the same split the Reports & Analytics screen uses.
 */
class BrandOwnerHomeService
{
    /** Expense types shown as "invoices" on the dashboard. */
    private const INVOICE_TYPES = ['single_invoice', 'grouped_invoice'];

    /** Expense types shown as plain "expenses" on the dashboard. */
    private const EXPENSE_TYPES = ['quick_cash'];

    /**
     * GET /brand-owner/dashboard/branches
     *
     * Flat branch list for the home screen branch selector. No pagination.
     *
     * @param  string[]  $allowedBranchIds  the owner's own brand's branches
     * @return array<int, array<string, mixed>>
     */
    public function getBranches(array $allowedBranchIds): array
    {
        return Branch::query()
            ->whereIn('id', $allowedBranchIds)
            ->orderBy('name')
            ->get(['id', 'name', 'image', 'opening_hours', 'closing_hours', 'lat', 'lng'])
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'image_url' => $branch->image ? asset('storage/'.$branch->image) : null,
                'opening_hours' => $this->openingHoursLabel($branch),
                'google_map_url' => $this->googleMapUrl($branch),
            ])
            ->all();
    }

    /**
     * GET /brand-owner/dashboard
     *
     * @param  array{branch_id: ?string, month: ?int, year: ?int, granularity: ?string}  $filters
     * @param  string[]  $allowedBranchIds  the owner's own brand's branches
     */
    public function getDashboard(array $filters, array $allowedBranchIds): array
    {
        // Default branch = the first branch when none is supplied. Both paths
        // stay inside the owner's brand, so a foreign branch_id resolves to
        // nothing rather than to another brand's figures.
        $branch = Branch::query()
            ->whereIn('id', $allowedBranchIds)
            ->when(
                ! empty($filters['branch_id']),
                fn ($q) => $q->whereKey($filters['branch_id']),
                fn ($q) => $q->orderBy('name'),
            )
            ->first();

        // A selected branch narrows to itself; "all" still means the owner's
        // OWN brand, never every brand's expenses.
        $branchIds = $branch !== null ? [$branch->id] : $allowedBranchIds;

        // Default the period to the current month / year.
        $month = $filters['month'] ?? (int) now()->month;
        $year = $filters['year'] ?? (int) now()->year;

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = (clone $periodStart)->endOfMonth();

        $invoices = $this->statusSummary(self::INVOICE_TYPES, $branchIds, $periodStart, $periodEnd);
        $expenses = $this->statusSummary(self::EXPENSE_TYPES, $branchIds, $periodStart, $periodEnd);

        return [
            'approved_invoices' => $invoices['approved_count'],
            'total_invoice_amount' => $invoices['approved_amount'],
            'pending_invoices' => $invoices['pending_count'],
            'approved_expenses' => $expenses['approved_count'],
            'total_expense_amount' => $expenses['approved_amount'],
            'pending_expenses' => $expenses['pending_count'],
            'granularity_chart_data' => [
                'daily' => $this->dailyTrend($branchIds, $month, $year),
                'weekly' => $this->weeklyTrend($branchIds, $month, $year),
                'monthly' => $this->monthlyTrend($branchIds, $month, $year),
            ],
            'response_month' => $month,
            'response_year' => $year,
            'response_branch_name' => $branch?->name ?? '',
        ];
    }

    // ----------------------------------------------------------------
    // Summary helpers
    // ----------------------------------------------------------------

    /**
     * Approved / pending counts + approved total for one expense category.
     *
     * `*_amount` is the approved spend only — the confirmed figure that
     * pairs with the approved count.
     *
     * @param  array<int, string>  $types
     * @return array{approved_count: int, approved_amount: float, pending_count: int}
     */
    private function statusSummary(array $types, array $branchIds, Carbon $start, Carbon $end): array
    {
        $rows = $this->expenseQuery($types, $branchIds, $start, $end)
            ->selectRaw('status, COUNT(*) as items, COALESCE(SUM(total_amount), 0) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'approved_count' => (int) ($rows['approved']->items ?? 0),
            'approved_amount' => (float) ($rows['approved']->amount ?? 0),
            'pending_count' => (int) ($rows['pending']->items ?? 0),
        ];
    }

    /**
     * Base expense query scoped to types, branch and a date window.
     *
     * @param  array<int, string>  $types
     */
    private function expenseQuery(array $types, array $branchIds, Carbon $start, Carbon $end): Builder
    {
        return Expense::query()
            ->whereIn('expense_type', $types)
            ->whereBetween('created_at', [$start, $end])
            ->whereHas(
                'branchManager',
                fn (Builder $m) => $m->whereIn('branch_id', $branchIds)
            );
    }

    // ----------------------------------------------------------------
    // Trend helpers — the chart visualises approved expense activity.
    // ----------------------------------------------------------------

    /** Daily granularity: the week around the reference day, day-by-day. */
    private function dailyTrend(array $branchIds, int $month, int $year): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
        $daysInMonth = $monthStart->daysInMonth;

        $isCurrentMonth = $month === (int) now()->month && $year === (int) now()->year;
        $referenceDay = $isCurrentMonth ? min((int) now()->day, $daysInMonth) : 1;

        $weekOfMonth = intdiv($referenceDay - 1, 7) + 1;
        $firstDay = ($weekOfMonth - 1) * 7 + 1;
        $lastDay = min($firstDay + 6, $daysInMonth);

        $start = $monthStart->copy()->day($firstDay)->startOfDay();
        $end = $monthStart->copy()->day($lastDay)->endOfDay();

        $rows = $this->approvedExpenseRows($branchIds, $start, $end);

        $chart = [];
        for ($day = $firstDay; $day <= $lastDay; $day++) {
            $chart[] = $this->sumWhere($rows, fn (Expense $e) => (int) $e->created_at->day === $day);
        }

        $total = (float) $rows->sum('total_amount');
        $previous = $this->approvedExpenseSum(
            $branchIds,
            $start->copy()->subDays(7),
            $start->copy()->subDay()->endOfDay()
        );

        return [
            'trend_period' => $monthStart->format('F').' '.$referenceDay.' (Week '.$weekOfMonth.')',
            'approved' => $rows->count(),
            'total_amount' => $total,
            'increase_percentage' => $this->percentageChange($total, $previous),
            'chart_data' => $chart,
        ];
    }

    /** Weekly granularity: the selected month, broken into 7-day weeks. */
    private function weeklyTrend(array $branchIds, int $month, int $year): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
        $monthEnd = (clone $monthStart)->endOfMonth();
        $daysInMonth = $monthStart->daysInMonth;
        $weekCount = (int) ceil($daysInMonth / 7);

        $rows = $this->approvedExpenseRows($branchIds, $monthStart, $monthEnd);

        $chart = [];
        for ($week = 0; $week < $weekCount; $week++) {
            $from = $week * 7 + 1;
            $to = min($from + 6, $daysInMonth);
            $chart[] = $this->sumWhere(
                $rows,
                fn (Expense $e) => (int) $e->created_at->day >= $from && (int) $e->created_at->day <= $to
            );
        }

        $total = (float) $rows->sum('total_amount');
        $prevStart = $monthStart->copy()->subMonthNoOverflow()->startOfMonth();
        $previous = $this->approvedExpenseSum($branchIds, $prevStart, (clone $prevStart)->endOfMonth());

        return [
            'trend_period' => $monthStart->format('F Y').' (Week 1-'.$weekCount.')',
            'approved' => $rows->count(),
            'total_amount' => $total,
            'increase_percentage' => $this->percentageChange($total, $previous),
            'chart_data' => $chart,
        ];
    }

    /** Monthly granularity: the selected year, broken into 12 months. */
    private function monthlyTrend(array $branchIds, int $month, int $year): array
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfYear();
        $yearEnd = (clone $yearStart)->endOfYear();

        $rows = $this->approvedExpenseRows($branchIds, $yearStart, $yearEnd);

        $chart = [];
        for ($m = 1; $m <= 12; $m++) {
            $chart[] = $this->sumWhere($rows, fn (Expense $e) => (int) $e->created_at->month === $m);
        }

        $total = (float) $rows->sum('total_amount');
        $prevStart = Carbon::create($year - 1, 1, 1)->startOfYear();
        $previous = $this->approvedExpenseSum($branchIds, $prevStart, (clone $prevStart)->endOfYear());

        return [
            'trend_period' => Carbon::create($year, $month, 1)->format('F Y'),
            'approved' => $rows->count(),
            'total_amount' => $total,
            'increase_percentage' => $this->percentageChange($total, $previous),
            'chart_data' => $chart,
        ];
    }

    /** Approved expenses (any type) in a window — rows kept for bucketing. */
    private function approvedExpenseRows(array $branchIds, Carbon $start, Carbon $end): Collection
    {
        return $this->approvedExpenseQuery($branchIds, $start, $end)
            ->get(['total_amount', 'created_at']);
    }

    /** Approved expense total (any type) in a window. */
    private function approvedExpenseSum(array $branchIds, Carbon $start, Carbon $end): float
    {
        return (float) $this->approvedExpenseQuery($branchIds, $start, $end)->sum('total_amount');
    }

    private function approvedExpenseQuery(array $branchIds, Carbon $start, Carbon $end): Builder
    {
        return Expense::query()
            ->where('status', 'approved')
            ->whereBetween('created_at', [$start, $end])
            ->whereHas(
                'branchManager',
                fn (Builder $m) => $m->whereIn('branch_id', $branchIds)
            );
    }

    /** Sum total_amount over the rows matching the predicate. */
    private function sumWhere(Collection $rows, callable $predicate): float
    {
        return (float) $rows->filter($predicate)->sum('total_amount');
    }

    /** Signed period-over-period percentage change, 1 decimal. */
    private function percentageChange(float $current, float $previous): float
    {
        if ($previous <= 0.0) {
            return $current > 0.0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    // ----------------------------------------------------------------
    // Branch field helpers
    // ----------------------------------------------------------------

    /**
     * "HH:MM - HH:MM" when both ends are known, else whatever is stored.
     *
     * Reads raw values so a non-time string survives the model's datetime
     * cast on `opening_hours` / `closing_hours`.
     */
    private function openingHoursLabel(Branch $branch): ?string
    {
        $opening = $branch->getRawOriginal('opening_hours');

        if (empty($opening)) {
            return null;
        }

        $closing = $branch->getRawOriginal('closing_hours');
        $opening = $this->shortTime((string) $opening);

        return empty($closing)
            ? $opening
            : $opening.' - '.$this->shortTime((string) $closing);
    }

    /** Trim seconds off an HH:MM:SS time; pass any other string through. */
    private function shortTime(string $value): string
    {
        $value = trim($value);

        return preg_match('/^(\d{1,2}:\d{2})(:\d{2})?$/', $value, $m) ? $m[1] : $value;
    }

    private function googleMapUrl(Branch $branch): ?string
    {
        if ($branch->lat === null || $branch->lng === null) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.$branch->lat.','.$branch->lng;
    }
}
