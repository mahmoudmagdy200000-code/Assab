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
     * Scope inputs (all resolved server-side; a client branch_id can only narrow):
     *  - $filters['allowed_brand_ids']  authorised BRAND ids — present for a brand
     *    actor (owner / brand manager); NULL for a branch-level manager.
     *  - $filters['allowed_branch_ids'] authorised BRANCH ids — the actor's current
     *    branches (own branch for a branch-level manager). Used for custody, the
     *    default-branch display, and to validate a requested narrowing branch.
     *  - $filters['branch_id']          optional client narrowing, honoured only
     *    when it is inside the authorised branch scope.
     *
     * R-01: expense aggregations key on each expense's IMMUTABLE origin — by
     * origin_asab_brand_id for a brand actor, by origin_branch_id for a
     * branch-level manager — never on a branch's current brand association. So a
     * branch re-tagged to another brand cannot move its historic expenses, and an
     * unlinked actor (empty scope) gets zeros, never another tenant's data.
     * Custody stays keyed on the CURRENT branch (operational cash, not expense
     * ownership).
     */
    public function getExpenseReportDetails(string $reportId, array $filters): array
    {
        $month = $filters['month'] ?? (int) now()->month;
        $year = $filters['year'] ?? (int) now()->year;
        $type = $filters['type'] ?? null;
        $status = $filters['status'] ?? null;
        $allowedBrandIds = $filters['allowed_brand_ids'] ?? null;
        $allowedBranchIds = $filters['allowed_branch_ids'] ?? [];
        $requestedBranchId = $filters['branch_id'] ?? null;

        // Current-branch scope for custody + branch display (narrowed by a valid
        // requested branch). Distinct from the immutable-origin expense scope.
        $custodyScopeIds = $this->effectiveBranchIds($allowedBranchIds, $requestedBranchId);

        // Immutable-origin expense scope (brand actor → origin brand; branch-level
        // manager → origin branch), applied inside every expense query below.
        $originScope = $this->expenseOriginScope($allowedBrandIds, $allowedBranchIds, $requestedBranchId);

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $prevStart = (clone $periodStart)->subMonth();

        $current = $this->monthExpenses($type, $status, $originScope, $month, $year);
        $previous = $this->monthExpenses($type, $status, $originScope, $prevStart->month, $prevStart->year);

        $totalAmount = (float) $current->sum('total_amount');
        $trend = $this->trend($totalAmount, (float) $previous->sum('total_amount'));

        // The narrowing branch is honoured only when it is inside the
        // authorised (current) branch scope; otherwise there is no branch.
        $branch = ($requestedBranchId !== null && in_array($requestedBranchId, $custodyScopeIds, true))
            ? Branch::find($requestedBranchId)
            : null;
        // default_branch is derived from the EFFECTIVE current-branch scope, not the
        // full authorised set — so an out-of-scope requested branch_id (empty
        // effective scope, zero aggregates) yields default_branch = null rather
        // than pointing at some other allowed branch the caller did not ask for
        // (IR-PKG2-03). With no branch_id the effective scope equals the
        // authorised set, so the default is unchanged.
        $defaultBranch = $branch
            ?? Branch::query()->whereIn('id', $custodyScopeIds)->orderBy('name')->first();
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
            'branch_comparisons' => $this->branchComparisons($type, $status, $month, $year, $originScope),
            'cash_transfer_log' => $this->cashTransferLog($month, $year, $custodyScopeIds),
            'quick_summary' => $this->quickSummary($originScope, $month, $year, $prevStart),
            'default_branch' => $this->branchShort($defaultBranch),
            'filters' => [
                'type' => $type,
                'status' => $status,
                'month' => $month,
                'year' => $year,
                'branch_id' => $requestedBranchId,
                'branch_name' => $branch?->name,
            ],
        ];
    }

    /**
     * GET /brand-owner/reports/custody/{reportId}
     *
     * Only branches inside the authorised scope are returned (the actor's own
     * branch for a branch-level manager, their brand's branches for a brand
     * actor); an optional $requestedBranchId narrows within that scope. An
     * empty scope returns no branches — never every branch.
     *
     * @param  string[]  $allowedBranchIds  authorised branch scope (server-resolved)
     */
    public function getCustodyReportDetails(string $reportId, ?int $month, ?int $year, array $allowedBranchIds = [], ?string $requestedBranchId = null): array
    {
        $month = $month ?? (int) now()->month;
        $year = $year ?? (int) now()->year;

        $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
        $monthEnd = (clone $monthStart)->endOfMonth();
        $monthName = $monthStart->format('F');

        $branches = Branch::query()
            ->whereIn('id', $this->effectiveBranchIds($allowedBranchIds, $requestedBranchId))
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
     * $owner is a BrandOwner / BrandManager or BranchManager. The generated
     * file is produced from getExpenseReportDetails, so it inherits the same
     * tenant scope — a brand actor exports by immutable origin brand, a
     * branch-level manager by origin branch (R-01).
     *
     * @param  string[]  $allowedBranchIds  authorised current-branch scope (server-resolved)
     * @param  string[]|null  $allowedBrandIds  authorised brand scope for a brand actor; NULL for a branch-level manager
     */
    public function exportExpenseReport(Model $owner, array $data, array $allowedBranchIds = [], ?string $requestedBranchId = null, ?array $allowedBrandIds = null): array
    {
        $year = (int) $data['year'];
        $month = (int) $data['month_number'];
        $type = $data['expense_type'];
        $format = $data['format_type'];

        $details = $this->getExpenseReportDetails('expenses', [
            'type' => $type,
            'month' => $month,
            'year' => $year,
            'allowed_brand_ids' => $allowedBrandIds,
            'allowed_branch_ids' => $allowedBranchIds,
            'branch_id' => $requestedBranchId,
        ]);

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
     * $owner is a BrandOwner / BrandManager or BranchManager. The generated
     * file inherits the same tenant scope as getCustodyReportDetails — only the
     * actor's authorised branches are exported.
     *
     * @param  string[]  $allowedBranchIds  authorised branch scope (server-resolved)
     */
    public function exportCustodyReport(Model $owner, array $data, array $allowedBranchIds = [], ?string $requestedBranchId = null): array
    {
        $year = (int) $data['year'];
        $month = (int) $data['month_number'];
        $format = $data['format_type'];

        $details = $this->getCustodyReportDetails('custody', $month, $year, $allowedBranchIds, $requestedBranchId);

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

    /**
     * The branch ids to aggregate over: the authorised scope, narrowed to a
     * requested branch only when that branch is itself inside the scope. A
     * requested branch outside the scope (or an empty scope) yields [] — which
     * every query below turns into "no rows", so nothing leaks.
     *
     * @param  string[]  $allowedBranchIds
     * @return string[]
     */
    private function effectiveBranchIds(array $allowedBranchIds, ?string $requestedBranchId): array
    {
        if ($requestedBranchId !== null) {
            return in_array($requestedBranchId, $allowedBranchIds, true) ? [$requestedBranchId] : [];
        }

        return array_values($allowedBranchIds);
    }

    /**
     * The immutable-origin WHERE for every expense aggregation (R-01).
     *
     * A brand actor ($allowedBrandIds is an array) is confined by the expense's
     * own origin_asab_brand_id against the actor's CURRENTLY authorised brands,
     * optionally narrowed to one branch's origin_branch_id when a valid branch_id
     * is requested. A branch-level manager ($allowedBrandIds === null) is confined
     * by origin_branch_id against their own (effective) branch scope. An empty
     * scope — or a requested branch outside the authorised set — matches nothing
     * (fail closed); a legacy NULL-origin row belongs to no brand and is never
     * guessed from a current association.
     *
     * @param  string[]|null  $allowedBrandIds
     * @param  string[]  $allowedBranchIds
     */
    private function expenseOriginScope(?array $allowedBrandIds, array $allowedBranchIds, ?string $requestedBranchId): \Closure
    {
        // Branch-level manager: immutable origin BRANCH within their own scope.
        if ($allowedBrandIds === null) {
            $branchIds = $this->effectiveBranchIds($allowedBranchIds, $requestedBranchId);

            return function (Builder $query) use ($branchIds): void {
                $branchIds === []
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('origin_branch_id', $branchIds);
            };
        }

        // Brand actor: immutable origin BRAND against currently authorised brands.
        $brandIds = array_values(array_unique(array_filter($allowedBrandIds)));

        // A requested branch narrows WITHIN the brand (by origin_branch_id) and
        // must itself be one of the actor's current authorised branches; an
        // out-of-scope narrowing yields no rows.
        $narrowBranchId = null;
        if ($requestedBranchId !== null) {
            if (! in_array($requestedBranchId, $allowedBranchIds, true)) {
                return fn (Builder $query) => $query->whereRaw('1 = 0');
            }
            $narrowBranchId = $requestedBranchId;
        }

        return function (Builder $query) use ($brandIds, $narrowBranchId): void {
            if ($brandIds === []) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('origin_asab_brand_id', $brandIds);
            if ($narrowBranchId !== null) {
                $query->where('origin_branch_id', $narrowBranchId);
            }
        };
    }

    /**
     * Expenses for one month, confined to the immutable-origin $originScope.
     */
    private function monthExpenses(?string $type, ?string $status, \Closure $originScope, int $month, int $year)
    {
        return $this->baseExpenseQuery($type, $status, $originScope)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->get();
    }

    /**
     * Base expense query, ALWAYS confined by the immutable-origin $originScope
     * (tenant isolation).
     *
     * FU-02 / R-01: the scope matches the expense's IMMUTABLE origin (brand for a
     * brand actor, branch for a branch-level manager) captured at creation, not
     * the manager's current branch nor a branch's current brand association, so a
     * historic expense stays with the owner that created it even after its branch
     * is re-tagged or its manager is reassigned. This also makes grouping
     * independent of the (possibly soft-deleted, possibly moved) manager —
     * superseding the earlier withTrashed eager-load workaround (FU-01).
     */
    private function baseExpenseQuery(?string $type, ?string $status, \Closure $originScope): Builder
    {
        $query = Expense::query()->with(['supplier:id,name']);
        $originScope($query);

        if ($type === 'quick_cash') {
            $query->where('expense_type', 'quick_cash');
        } elseif ($type === 'invoice') {
            $query->whereIn('expense_type', self::INVOICE_TYPES);
        }

        if ($status) {
            $query->where('status', $status);
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
     * Confined by the immutable-origin $originScope, so only authorised
     * expenses are compared.
     */
    private function branchComparisons(?string $type, ?string $status, int $month, int $year, \Closure $originScope): array
    {
        $expenses = $this->monthExpenses($type, $status, $originScope, $month, $year);

        // FU-02: group by the immutable originating branch and resolve its name
        // directly (not through the mutable, possibly soft-deleted manager).
        $branchNames = Branch::query()
            ->whereIn('id', $expenses->pluck('origin_branch_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $byBranch = $expenses
            ->groupBy(fn (Expense $e) => $e->origin_branch_id ?: 'unassigned')
            ->map(fn ($group, $branchId) => [
                'name' => $branchNames[$branchId] ?? 'Unassigned',
                'amount' => (float) $group->sum('total_amount'),
            ])
            ->sortByDesc('amount')
            ->values();

        $maxAmount = (float) ($byBranch->max('amount') ?? 0.0);

        return $byBranch
            ->map(fn (array $row) => array_merge($row, ['max_amount' => $maxAmount]))
            ->all();
    }

    /** @param  string[]  $branchIds */
    private function cashTransferLog(int $month, int $year, array $branchIds): array
    {
        $transactions = CustodyTransaction::query()
            ->with('branch:id,name')
            ->whereIn('branch_id', $branchIds)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->orderByDesc('transaction_date')
            ->get();

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

    private function quickSummary(\Closure $originScope, int $month, int $year, Carbon $prevStart): array
    {
        $current = (float) $this->quickCashQuery($originScope)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->sum('total_amount');

        $previous = (float) $this->quickCashQuery($originScope)
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

    /**
     * Quick-cash expenses, ALWAYS confined by the immutable-origin $originScope
     * (tenant isolation).
     */
    private function quickCashQuery(\Closure $originScope): Builder
    {
        $query = Expense::query()->where('expense_type', 'quick_cash');
        $originScope($query);

        return $query;
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
