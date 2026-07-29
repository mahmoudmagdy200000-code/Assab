<?php

namespace Modules\BrandOwner\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;
use Modules\BrandOwner\Models\BrandOwnerReportExport;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Expense\Models\Expense;

/**
 * Builds the Brand Owner Reports & Analytics screen payloads:
 *  - reports list + export history
 *  - expense report details (summary, payment methods, suppliers, ratios,
 *    branch comparisons, cash transfer log, quick summary)
 *  - custody report details (per-branch balance summaries)
 *  - PDF / Excel exports
 */
class BrandOwnerReportsService
{
    /** Expense types treated as "invoice" for the brand-owner filter. */
    private const INVOICE_TYPES = ['single_invoice', 'grouped_invoice'];

    public function __construct(
        private ReportFileExportService $exporter
    ) {}

    /**
     * GET /brand-owner/reports-and-analytics
     *
     * $owner is a BrandOwner or a BranchManager. Export history is scoped to
     * whoever generated it, so a branch manager only sees their own exports.
     */
    public function getReportsAndAnalytics(Model $owner): array
    {
        $now = now();

        $reports = [
            [
                'id' => 'expenses',
                'type' => 'expenses',
                'period_label' => $now->toIso8601String(),
                'status_label' => 'completed',
            ],
            [
                'id' => 'custody',
                'type' => 'custody',
                'period_label' => $now->toIso8601String(),
                'status_label' => 'completed',
            ],
        ];

        $history = BrandOwnerReportExport::query()
            ->where('brand_owner_id', $owner->getKey())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (BrandOwnerReportExport $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'created_at_label' => optional($e->created_at)->toIso8601String(),
                'download_url' => $e->download_url,
                'type' => $e->format,
            ])
            ->all();

        return [
            'reports' => $reports,
            'export_history' => $history,
        ];
    }

    /**
     * GET /brand-owner/reports/expense/{reportId}
     *
     * When $filters['scope_branch_only'] is set (branch-manager callers), the
     * whole payload — including branch comparisons and the default branch — is
     * restricted to $filters['branch_id'] so no other branch's data leaks.
     */
    public function getExpenseReportDetails(string $reportId, array $filters): array
    {
        $month = $filters['month'] ?? (int) now()->month;
        $year = $filters['year'] ?? (int) now()->year;
        $type = $filters['type'] ?? null;
        $status = $filters['status'] ?? null;
        $branchId = $filters['branch_id'] ?? null;
        $scopeBranchOnly = ! empty($filters['scope_branch_only']) && $branchId !== null;

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $prevStart = (clone $periodStart)->subMonth();

        $current = $this->monthExpenses($type, $status, $branchId, $month, $year);
        $previous = $this->monthExpenses($type, $status, $branchId, $prevStart->month, $prevStart->year);

        $totalAmount = (float) $current->sum('total_amount');
        $trend = $this->trend($totalAmount, (float) $previous->sum('total_amount'));

        $branch = $branchId ? Branch::find($branchId) : null;
        $defaultBranch = $scopeBranchOnly && $branch
            ? $branch
            : Branch::query()->orderBy('name')->first();
        $summaryBranch = $branch ?? $defaultBranch;

        return [
            'summary' => [
                'branch' => $this->branchShort($summaryBranch),
                'period_label' => $periodStart->format('F Y'),
                'total_requests' => $current->count(),
                'total_amount' => $totalAmount,
                'trend_percent' => $trend['trend_percent'],
                'trend_label' => $trend['trend_label'],
                'trend_is_up' => $trend['trend_is_up'],
            ],
            'payment_methods' => $this->paymentMethods($current),
            'top_suppliers' => $this->topSuppliers($current),
            'expense_ratios' => $this->expenseRatios($current),
            'branch_comparisons' => $this->branchComparisons($type, $status, $month, $year, $scopeBranchOnly ? $branchId : null),
            'cash_transfer_log' => $this->cashTransferLog($month, $year, $branchId),
            'quick_summary' => $this->quickSummary($branchId, $month, $year, $prevStart),
            'default_branch' => $this->branchShort($defaultBranch),
            'filters' => [
                'type' => $type,
                'status' => $status,
                'month' => $month,
                'year' => $year,
                'branch_id' => $branchId,
                'branch_name' => $branch?->name,
            ],
        ];
    }

    /**
     * GET /brand-owner/reports/custody/{reportId}
     *
     * When $branchId is provided (branch-manager callers) only that branch is
     * returned; brand owners pass null and receive every branch.
     */
    public function getCustodyReportDetails(string $reportId, ?int $month, ?int $year, ?string $branchId = null): array
    {
        $month = $month ?? (int) now()->month;
        $year = $year ?? (int) now()->year;

        $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
        $monthEnd = (clone $monthStart)->endOfMonth();
        $monthName = $monthStart->format('F');

        $branches = Branch::query()
            ->when($branchId, fn ($q) => $q->where('id', $branchId))
            ->orderBy('name')
            ->get()
            ->map(function (Branch $b) use ($monthStart, $monthEnd, $monthName) {
                $opening = $this->custodyBalanceBefore($b->id, $monthStart);
                $cashIn = (float) CustodyTransaction::query()
                    ->where('branch_id', $b->id)
                    ->where('is_cash_in', true)
                    ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                    ->sum('amount');
                $cashOut = (float) CustodyTransaction::query()
                    ->where('branch_id', $b->id)
                    ->where('is_cash_in', false)
                    ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                    ->sum('amount');
                $currentBalance = $opening + $cashIn - $cashOut;

                return [
                    'image_url' => $b->image ? asset('storage/'.$b->image) : null,
                    'name' => $b->name,
                    'branch' => $b->location,
                    'current_balance' => $currentBalance,
                    'amounts' => [
                        'month_name' => $monthName,
                        'month_opening_balance' => $opening,
                        'total_cash_in' => $cashIn,
                        'total_cash_out' => $cashOut,
                        'current_balance' => $currentBalance,
                    ],
                ];
            })->all();

        return [
            'period_label' => $monthName.' Custody Summary',
            'branches' => $branches,
        ];
    }

    /**
     * POST /brand-owner/reports/expense/export
     *
     * $owner is a BrandOwner or BranchManager. When $branchId is provided
     * (branch-manager callers) the generated file covers that branch only.
     */
    public function exportExpenseReport(Model $owner, array $data, ?string $branchId = null): array
    {
        $year = (int) $data['year'];
        $month = (int) $data['month_number'];
        $type = $data['expense_type'];
        $format = $data['format_type'];

        $filters = [
            'type' => $type,
            'month' => $month,
            'year' => $year,
        ];

        if ($branchId !== null) {
            $filters['branch_id'] = $branchId;
            $filters['scope_branch_only'] = true;
        }

        $details = $this->getExpenseReportDetails('expenses', $filters);

        $periodLabel = Carbon::create($year, $month, 1)->format('F Y');
        $title = ucfirst(str_replace('_', ' ', $type)).' Expense Report - '.$periodLabel;

        $filePath = $this->exporter->exportExpense($details, $format, $title);

        $export = BrandOwnerReportExport::create([
            'brand_owner_id' => $owner->getKey(),
            'report_kind' => 'expenses',
            'format' => $this->normalizeFormat($format),
            'title' => $title,
            'file_path' => $filePath,
            'params' => $data,
        ]);

        return ['file_url' => $export->download_url];
    }

    /**
     * POST /brand-owner/reports/custody/export
     *
     * $owner is a BrandOwner or BranchManager. When $branchId is provided
     * (branch-manager callers) the generated file covers that branch only.
     */
    public function exportCustodyReport(Model $owner, array $data, ?string $branchId = null): array
    {
        $year = (int) $data['year'];
        $month = (int) $data['month_number'];
        $format = $data['format_type'];

        $details = $this->getCustodyReportDetails('custody', $month, $year, $branchId);

        $periodLabel = Carbon::create($year, $month, 1)->format('F Y');
        $title = 'Custody Report - '.$periodLabel;

        $filePath = $this->exporter->exportCustody($details, $format, $title);

        $export = BrandOwnerReportExport::create([
            'brand_owner_id' => $owner->getKey(),
            'report_kind' => 'custody',
            'format' => $this->normalizeFormat($format),
            'title' => $title,
            'file_path' => $filePath,
            'params' => $data,
        ]);

        return ['file_url' => $export->download_url];
    }

    /**
     * GET /brand-owner/branches
     *
     * When $branchId is provided (branch-manager callers) only that branch is
     * returned; brand owners pass null and receive their own brand's branches.
     *
     * @param  string[]  $allowedBranchIds  brand isolation, resolved per caller
     */
    public function getBranches(?string $branchId = null, array $allowedBranchIds = []): array
    {
        $branches = Branch::query()
            ->whereIn('id', $allowedBranchIds)
            ->when($branchId, fn (Builder $q) => $q->where('id', $branchId))
            ->with(['branchManager:id,branch_id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'managerName' => $b->branchManager?->name,
            ])
            ->all();

        return ['branches' => $branches];
    }

    // ----------------------------------------------------------------
    // Internal helpers
    // ----------------------------------------------------------------

    /** Expenses for one month with the brand-owner filters applied. */
    private function monthExpenses(?string $type, ?string $status, ?string $branchId, int $month, int $year)
    {
        return $this->baseExpenseQuery($type, $status, $branchId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->get();
    }

    private function baseExpenseQuery(?string $type, ?string $status, ?string $branchId): Builder
    {
        $query = Expense::query()->with(['branchManager.branch', 'supplier:id,name']);

        if ($type === 'quick_cash') {
            $query->where('expense_type', 'quick_cash');
        } elseif ($type === 'invoice') {
            $query->whereIn('expense_type', self::INVOICE_TYPES);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($branchId) {
            $query->whereHas('branchManager', fn (Builder $m) => $m->where('branch_id', $branchId));
        }

        return $query;
    }

    private function paymentMethods($expenses): array
    {
        return $expenses
            ->groupBy(fn (Expense $e) => $e->payment_method ?: 'unspecified')
            ->map(fn ($group, $method) => [
                'method' => $method,
                'count' => $group->count(),
                'amount' => (float) $group->sum('total_amount'),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    private function topSuppliers($expenses): array
    {
        return $expenses
            ->filter(fn (Expense $e) => ! empty($e->supplier_id))
            ->groupBy('supplier_id')
            ->map(fn ($group) => [
                'name' => $group->first()->supplier?->name ?? 'Unknown',
                'amount' => (float) $group->sum('total_amount'),
            ])
            ->sortByDesc('amount')
            ->take(5)
            ->values()
            ->all();
    }

    private function expenseRatios($expenses): array
    {
        $tax = $expenses->filter(fn (Expense $e) => (float) $e->vat_amount > 0);
        $nonTax = $expenses->filter(fn (Expense $e) => (float) $e->vat_amount <= 0);

        return [
            [
                'type' => 'tax',
                'count' => $tax->count(),
                'amount' => (float) $tax->sum('total_amount'),
            ],
            [
                'type' => 'non_tax',
                'count' => $nonTax->count(),
                'amount' => (float) $nonTax->sum('total_amount'),
            ],
        ];
    }

    /**
     * Per-branch totals for the period; max_amount lets the client draw bars.
     *
     * $branchId restricts the comparison to a single branch (branch-manager
     * callers); brand owners pass null to compare every branch.
     */
    private function branchComparisons(?string $type, ?string $status, int $month, int $year, ?string $branchId = null): array
    {
        $expenses = $this->monthExpenses($type, $status, $branchId, $month, $year);

        $byBranch = $expenses
            ->groupBy(fn (Expense $e) => $e->branchManager?->branch_id ?: 'unassigned')
            ->map(fn ($group) => [
                'name' => $group->first()->branchManager?->branch?->name ?? 'Unassigned',
                'amount' => (float) $group->sum('total_amount'),
            ])
            ->sortByDesc('amount')
            ->values();

        $maxAmount = (float) ($byBranch->max('amount') ?? 0.0);

        return $byBranch
            ->map(fn (array $row) => array_merge($row, ['max_amount' => $maxAmount]))
            ->all();
    }

    private function cashTransferLog(int $month, int $year, ?string $branchId): array
    {
        $query = CustodyTransaction::query()
            ->with('branch:id,name')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $transactions = $query->orderByDesc('transaction_date')->get();

        $logs = $transactions->map(fn (CustodyTransaction $t) => [
            'method' => $t->handover_method ?: $t->type,
            'sub_label' => $t->is_cash_in ? 'Cash In' : 'Cash Out',
            'branch' => $t->branch?->name,
            'date_time' => optional($t->transaction_date)->toIso8601String(),
            'amount' => (float) $t->amount,
        ])->all();

        return [
            'total_cash_in' => (float) $transactions->where('is_cash_in', true)->sum('amount'),
            'total_cash_out' => (float) $transactions->where('is_cash_in', false)->sum('amount'),
            'count' => $transactions->count(),
            'logs' => $logs,
        ];
    }

    private function quickSummary(?string $branchId, int $month, int $year, Carbon $prevStart): array
    {
        $current = (float) $this->quickCashQuery($branchId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->sum('total_amount');

        $previous = (float) $this->quickCashQuery($branchId)
            ->whereYear('created_at', $prevStart->year)
            ->whereMonth('created_at', $prevStart->month)
            ->sum('total_amount');

        $trend = $this->trend($current, $previous);

        return [
            'amount' => $current,
            'trend_percent' => $trend['trend_percent'],
            'trend_label' => $trend['trend_label'],
            'trend_is_up' => $trend['trend_is_up'],
        ];
    }

    private function quickCashQuery(?string $branchId): Builder
    {
        return Expense::query()
            ->where('expense_type', 'quick_cash')
            ->when($branchId, fn (Builder $q) => $q->whereHas(
                'branchManager',
                fn (Builder $m) => $m->where('branch_id', $branchId)
            ));
    }

    /** Net custody balance for a branch from all transactions before $date. */
    private function custodyBalanceBefore(string $branchId, Carbon $date): float
    {
        $cashIn = (float) CustodyTransaction::query()
            ->where('branch_id', $branchId)
            ->where('is_cash_in', true)
            ->where('transaction_date', '<', $date)
            ->sum('amount');

        $cashOut = (float) CustodyTransaction::query()
            ->where('branch_id', $branchId)
            ->where('is_cash_in', false)
            ->where('transaction_date', '<', $date)
            ->sum('amount');

        return $cashIn - $cashOut;
    }

    private function branchShort(?Branch $branch): ?array
    {
        if (! $branch) {
            return null;
        }

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'image_url' => $branch->image ? asset('storage/'.$branch->image) : null,
        ];
    }

    /** Month-over-month trend as a signed percentage string. */
    private function trend(float $current, float $previous): array
    {
        if ($previous <= 0.0) {
            $percent = $current > 0.0 ? 100.0 : 0.0;
        } else {
            $percent = (($current - $previous) / $previous) * 100;
        }

        $isUp = $percent >= 0.0;

        return [
            'trend_percent' => ($isUp ? '+' : '').number_format($percent, 1).'%',
            'trend_label' => 'vs last month',
            'trend_is_up' => $isUp,
        ];
    }

    private function normalizeFormat(string $formatType): string
    {
        return strtolower($formatType) === 'excel' ? 'excel' : 'pdf';
    }
}
