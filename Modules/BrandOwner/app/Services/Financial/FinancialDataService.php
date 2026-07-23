<?php

namespace Modules\BrandOwner\Services\Financial;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BrandOwner\Models\CashSalesTransferRequest;
use Modules\Expense\Models\Expense;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftSalesBreakdown;

/**
 * Single source of truth for every Brand Owner financial report.
 *
 * All eight financial reports (P&L, sales-channel, smart-comparison, break-even,
 * operational profitability, menu engineering, profit-vs-cash, and the two
 * simulators) read their numbers through this one service so a figure is
 * computed identically everywhere.
 *
 * DATA MODEL MAPPING (this backend is the legacy mobile world — there is no POS
 * transaction table, so figures are derived from the tables that DO exist):
 *
 *  - Revenue / turnover ....... SUM(cashier_shifts.total_sales)  (per branch, per
 *                               period; branch via shifts.branch_id, window on
 *                               cashier_shifts.shift_date)
 *  - Sales by channel ......... shift_sales_breakdown.amount grouped by
 *                               aggregator_id (an aggregator IS a sales channel;
 *                               commission_rate lives on the aggregator)
 *  - Direct cost (COGS) ....... approved invoice-type expenses (supplier
 *                               purchases: single_invoice + grouped_invoice)
 *  - Operating / G&A .......... approved quick_cash expenses
 *  - Item selling price ....... branch_item.price (branch-specific)
 *
 * PRODUCTION COST (best-available, real first):
 *  - Real: the item's most recent purchase unit price
 *    (purchase_order_items.unit_price, latest by created_at).
 *  - Fallback: branch_item.price * self::COST_RATIO when the item has no purchase
 *    history or the recorded cost is not a sane margin input (<=0 or >= price).
 *
 * DOCUMENTED DERIVATIONS (no dedicated source column exists for these):
 *  - Fixed-cost split ......... operating expenses apportioned by
 *                               self::FIXED_COST_SPLIT (rent / salaries / insurance)
 * These are centralised here (not scattered across reports) and clearly flagged
 * so a real source can replace them in one place later.
 */
class FinancialDataService
{
    /** Expense types treated as direct cost / COGS (supplier purchases). */
    public const DIRECT_COST_TYPES = ['single_invoice', 'grouped_invoice'];

    /** Expense types treated as operating / G&A spend. */
    public const OPERATING_TYPES = ['quick_cash'];

    /** Assumed production cost as a fraction of selling price (see class doc). */
    public const COST_RATIO = 0.40;

    /** How operating spend is apportioned into fixed-cost buckets (sums to 1.0). */
    public const FIXED_COST_SPLIT = [
        'rent_and_utilities' => 0.40,
        'salaries' => 0.50,
        'insurance_and_licenses' => 0.10,
    ];

    // ----------------------------------------------------------------
    // Period + branch resolution
    // ----------------------------------------------------------------

    /**
     * Resolve a year/month (defaulting to the current month) into a bounded window.
     *
     * @return array{start: Carbon, end: Carbon, year: int, month: int, month_name: string}
     */
    public function resolvePeriod(?int $year, ?int $month): array
    {
        $year = $year ?: (int) now()->year;
        $month = $month ?: (int) now()->month;

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        return [
            'start' => $start,
            'end' => $end,
            'year' => $year,
            'month' => $month,
            'month_name' => $start->format('F'),
        ];
    }

    public function monthName(int $month): string
    {
        return Carbon::create(2000, max(1, min(12, $month)), 1)->format('F');
    }

    /**
     * The calendar month immediately before the given one.
     *
     * @return array{year: int, month: int, month_name: string}
     */
    public function previousMonth(int $year, int $month): array
    {
        $prev = Carbon::create($year, $month, 1)->subMonthNoOverflow();

        return [
            'year' => (int) $prev->year,
            'month' => (int) $prev->month,
            'month_name' => $prev->format('F'),
        ];
    }

    /** Find a branch by id, or fall back to the first branch (alphabetical). */
    public function resolveBranch(?string $branchId): ?Branch
    {
        return ! empty($branchId)
            ? Branch::query()->find($branchId)
            : Branch::query()->orderBy('name')->first();
    }

    public function branchName(?string $branchId): ?string
    {
        if (empty($branchId)) {
            return null;
        }

        return Branch::query()->whereKey($branchId)->value('name');
    }

    /**
     * Resolve the effective branch for a report: the requested branch when a valid
     * id is supplied, otherwise the first branch (alphabetical) as a sensible
     * default so brand-owner dashboards always render a real branch's figures.
     * An unknown id also falls back to the first branch rather than silently
     * aggregating every branch.
     *
     * @return array{id: ?string, name: ?string} both null only when no branch exists
     */
    public function resolveBranchRef(?string $branchId): array
    {
        $branch = $this->resolveBranch($branchId) ?? $this->resolveBranch(null);

        return [
            'id' => $branch ? (string) $branch->id : null,
            'name' => $branch ? (string) $branch->name : null,
        ];
    }

    /**
     * Flat branch list [{id, name}] — optionally restricted to one branch
     * (branch-manager callers pass their own id; brand owners pass null).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function branches(?string $branchId = null): array
    {
        return Branch::query()
            ->when($branchId, fn (Builder $q) => $q->whereKey($branchId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $b) => ['id' => (string) $b->id, 'name' => (string) $b->name])
            ->all();
    }

    // ----------------------------------------------------------------
    // Revenue (cashier_shifts)
    // ----------------------------------------------------------------

    public function totalRevenue(?string $branchId, Carbon $start, Carbon $end): float
    {
        return (float) $this->shiftQuery($branchId, $start, $end)->sum('total_sales');
    }

    public function netRevenue(?string $branchId, Carbon $start, Carbon $end): float
    {
        return (float) $this->shiftQuery($branchId, $start, $end)->sum('net_sales');
    }

    public function vatCollected(?string $branchId, Carbon $start, Carbon $end): float
    {
        return (float) $this->shiftQuery($branchId, $start, $end)->sum('vat_amount');
    }

    /** Order count — proxied by the number of channel sales breakdown rows. */
    public function orderCount(?string $branchId, Carbon $start, Carbon $end): int
    {
        return (int) $this->breakdownQuery($branchId, $start, $end)->count();
    }

    /** Base cashier-shift query scoped to a branch (via shift) and date window. */
    private function shiftQuery(?string $branchId, Carbon $start, Carbon $end): Builder
    {
        return CashierShift::query()
            ->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
            ->when($branchId, fn (Builder $q) => $q->whereHas(
                'shift',
                fn (Builder $s) => $s->where('branch_id', $branchId)
            ));
    }

    // ----------------------------------------------------------------
    // Sales channels (shift_sales_breakdown → aggregator)
    // ----------------------------------------------------------------

    /**
     * Sales grouped by channel (aggregator) for the window.
     *
     * @return Collection<int, array{
     *   aggregator_id: string, name: string, code: ?string, logo_url: ?string,
     *   commission_rate: float, amount: float, order_count: int
     * }>
     */
    public function channelSales(?string $branchId, Carbon $start, Carbon $end): Collection
    {
        return $this->breakdownQuery($branchId, $start, $end)
            ->with('aggregator:id,name,code,logo,commission_rate')
            ->get(['id', 'aggregator_id', 'amount'])
            ->groupBy('aggregator_id')
            ->map(function (Collection $rows) {
                $aggregator = $rows->first()->aggregator;
                $amount = (float) $rows->sum('amount');
                $rate = (float) ($aggregator->commission_rate ?? 0);

                return [
                    'aggregator_id' => (string) $rows->first()->aggregator_id,
                    'name' => (string) ($aggregator->name ?? 'Unknown'),
                    'code' => $aggregator->code ?? null,
                    'logo_url' => $aggregator && $aggregator->logo ? asset('storage/'.$aggregator->logo) : null,
                    'commission_rate' => $rate,
                    'amount' => $amount,
                    'order_count' => $rows->count(),
                ];
            })
            ->sortByDesc('amount')
            ->values();
    }

    /** Base breakdown query scoped to a branch (via shift) and shift date window. */
    private function breakdownQuery(?string $branchId, Carbon $start, Carbon $end): Builder
    {
        return ShiftSalesBreakdown::query()
            ->whereHas('cashierShift', function (Builder $q) use ($branchId, $start, $end) {
                $q->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
                    ->when($branchId, fn (Builder $qq) => $qq->whereHas(
                        'shift',
                        fn (Builder $s) => $s->where('branch_id', $branchId)
                    ));
            });
    }

    // ----------------------------------------------------------------
    // Costs (expenses)
    // ----------------------------------------------------------------

    public function directCost(?string $branchId, Carbon $start, Carbon $end): float
    {
        return (float) $this->expenseQuery($branchId, $start, $end)
            ->whereIn('expense_type', self::DIRECT_COST_TYPES)
            ->sum('total_amount');
    }

    public function operatingExpenses(?string $branchId, Carbon $start, Carbon $end): float
    {
        return (float) $this->expenseQuery($branchId, $start, $end)
            ->whereIn('expense_type', self::OPERATING_TYPES)
            ->sum('total_amount');
    }

    public function totalExpenses(?string $branchId, Carbon $start, Carbon $end): float
    {
        return $this->directCost($branchId, $start, $end)
            + $this->operatingExpenses($branchId, $start, $end);
    }

    /** VAT paid on approved expenses (input tax) — used by zakat/tax provisioning. */
    public function expenseVat(?string $branchId, Carbon $start, Carbon $end): float
    {
        return (float) $this->expenseQuery($branchId, $start, $end)->sum('vat_amount');
    }

    /** Approved base expense query scoped to a branch and creation window. */
    private function expenseQuery(?string $branchId, Carbon $start, Carbon $end): Builder
    {
        return Expense::query()
            ->where('status', 'approved')
            ->whereBetween('created_at', [$start, $end])
            ->when($branchId, fn (Builder $q) => $q->whereHas(
                'branchManager',
                fn (Builder $m) => $m->where('branch_id', $branchId)
            ));
    }

    /**
     * Unpaid supplier obligations — invoice-type expenses still pending, paid via
     * a supplier, within the window.
     *
     * @return array{total: float, items: array<int, array{name: string, amount: float}>}
     */
    public function supplierObligations(?string $branchId, Carbon $start, Carbon $end): array
    {
        $rows = Expense::query()
            ->with('supplier:id,name')
            ->where('status', 'pending')
            ->where('payment_method', 'supplier')
            ->whereIn('expense_type', self::DIRECT_COST_TYPES)
            ->whereBetween('created_at', [$start, $end])
            ->when($branchId, fn (Builder $q) => $q->whereHas(
                'branchManager',
                fn (Builder $m) => $m->where('branch_id', $branchId)
            ))
            ->get(['id', 'supplier_id', 'total_amount']);

        $items = $rows
            ->groupBy('supplier_id')
            ->map(fn (Collection $g) => [
                'name' => (string) ($g->first()->supplier?->name ?? 'Unknown supplier'),
                'amount' => (float) $g->sum('total_amount'),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        return [
            'total' => (float) $rows->sum('total_amount'),
            'items' => $items,
        ];
    }

    // ----------------------------------------------------------------
    // Derived cost structure
    // ----------------------------------------------------------------

    /**
     * Fixed-cost breakdown. DERIVED: this backend has no rent/salary ledger, so
     * operating (quick_cash) spend is apportioned by self::FIXED_COST_SPLIT.
     *
     * @return array{rent_and_utilities: float, salaries: float, insurance_and_licenses: float, total_fixed: float}
     */
    public function fixedCosts(?string $branchId, Carbon $start, Carbon $end): array
    {
        $operating = $this->operatingExpenses($branchId, $start, $end);

        $rent = round($operating * self::FIXED_COST_SPLIT['rent_and_utilities'], 2);
        $salaries = round($operating * self::FIXED_COST_SPLIT['salaries'], 2);
        $insurance = round($operating * self::FIXED_COST_SPLIT['insurance_and_licenses'], 2);

        return [
            'rent_and_utilities' => $rent,
            'salaries' => $salaries,
            'insurance_and_licenses' => $insurance,
            'total_fixed' => round($rent + $salaries + $insurance, 2),
        ];
    }

    /**
     * Full P&L rollup for a branch + window.
     *
     * @return array{
     *   turnover: float, direct_cost: float, gross_profit: float,
     *   operating_expenses: float, total_cost: float, net_profit: float,
     *   profit_margin: float, is_profit: bool
     * }
     */
    public function profitAndLoss(?string $branchId, Carbon $start, Carbon $end): array
    {
        $turnover = $this->totalRevenue($branchId, $start, $end);
        $directCost = $this->directCost($branchId, $start, $end);
        $operating = $this->operatingExpenses($branchId, $start, $end);

        $grossProfit = round($turnover - $directCost, 2);
        $totalCost = round($directCost + $operating, 2);
        $netProfit = round($turnover - $totalCost, 2);

        return [
            'turnover' => round($turnover, 2),
            'direct_cost' => round($directCost, 2),
            'gross_profit' => $grossProfit,
            'operating_expenses' => round($operating, 2),
            'total_cost' => $totalCost,
            'net_profit' => $netProfit,
            'profit_margin' => $this->ratio($netProfit, $turnover),
            'is_profit' => $netProfit >= 0,
        ];
    }

    // ----------------------------------------------------------------
    // Cash + inventory + workforce (for profit-vs-cash / operational reports)
    // ----------------------------------------------------------------

    /** Cash actually handed to the owner in the window (approved cash transfers). */
    public function cashOnHand(?string $branchId, Carbon $start, Carbon $end): float
    {
        return (float) CashSalesTransferRequest::query()
            ->where('status', 'approved')
            ->whereBetween('handover_date', [$start, $end])
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->sum('handover_amount');
    }

    /** Book value of on-hand inventory = SUM(branch_item.price * quantity). */
    public function inventoryValue(?string $branchId): float
    {
        return (float) BranchItem::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->get(['price', 'quantity'])
            ->sum(fn (BranchItem $bi) => (float) $bi->price * (float) $bi->quantity);
    }

    /** Head-count backing the branch(es): active branch managers + cashiers. */
    public function employeeCount(?string $branchId): int
    {
        $managers = \Modules\BranchManagers\Models\BranchManager::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->count();

        $cashiers = \Modules\Cashier\Models\Cashier::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->count();

        return max(1, $managers + $cashiers);
    }

    // ----------------------------------------------------------------
    // Items / menu
    // ----------------------------------------------------------------

    /**
     * Branch items with their catalog item eager-loaded, for menu engineering
     * and the price simulator.
     *
     * @return Collection<int, BranchItem>
     */
    public function branchItems(?string $branchId): Collection
    {
        return BranchItem::query()
            ->with('item:id,name,category,subcategory,logo,is_active')
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereHas('item', fn (Builder $q) => $q->where('is_active', true))
            ->get();
    }

    /**
     * Latest real purchase unit price per item id, from purchase_order_items.
     * Ordered ascending so keyBy keeps the most recent row per item.
     *
     * @param  array<int, string|null>  $itemIds
     * @return array<string, float> item_id => unit_price
     */
    public function latestPurchaseUnitCosts(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter($itemIds)));

        if (empty($itemIds)) {
            return [];
        }

        return PurchaseOrderItem::query()
            ->whereIn('item_id', $itemIds)
            ->orderBy('created_at')
            ->get(['item_id', 'unit_price'])
            ->keyBy('item_id')
            ->map(fn (PurchaseOrderItem $r) => (float) $r->unit_price)
            ->all();
    }

    /**
     * Resolve an item's production cost: prefer the real latest purchase price,
     * fall back to the COST_RATIO estimate when it is absent or not a sane margin
     * input (<=0, or >= the selling price).
     */
    public function resolveItemCost(float $price, ?float $purchaseCost): float
    {
        if ($purchaseCost !== null && $purchaseCost > 0 && $purchaseCost < $price) {
            return round($purchaseCost, 2);
        }

        return $this->productionCost($price);
    }

    /** Convenience single-item production cost (one purchase lookup + fallback). */
    public function itemProductionCost(?string $itemId, float $price): float
    {
        $map = $itemId ? $this->latestPurchaseUnitCosts([$itemId]) : [];

        return $this->resolveItemCost($price, $map[$itemId] ?? null);
    }

    /** COST_RATIO estimate of production cost for a selling price (fallback). */
    public function productionCost(float $price): float
    {
        return round($price * self::COST_RATIO, 2);
    }

    // ----------------------------------------------------------------
    // Math helpers
    // ----------------------------------------------------------------

    /** Signed period-over-period percentage change, 2 decimals. */
    public function percentageChange(float $current, float $previous): float
    {
        if ($previous == 0.0) {
            return $current > 0.0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    /** part / whole as a percentage (0 when whole is 0), 2 decimals. */
    public function ratio(float $part, float $whole): float
    {
        return $whole == 0.0 ? 0.0 : round(($part / $whole) * 100, 2);
    }
}
