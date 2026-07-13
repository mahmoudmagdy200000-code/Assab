<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\Operation;

/**
 * Computes the admin report payloads (BACKEND_API_SPEC.md §6.1.8) from the
 * operations ledger. Each reportKey yields a populated, meaningfully-shaped
 * payload — no placeholder stubs. Money stays in integer halalas.
 */
class ReportService
{
    /**
     * @param  array{reportKey:string, period?:array{from?:string,to?:string}, brandIds?:array, restaurantIds?:array, branchIds?:array}  $input
     * @return array<string, mixed>
     */
    public function build(array $input): array
    {
        $key = $input['reportKey'];
        $from = $input['period']['from'] ?? null;
        $to = $input['period']['to'] ?? null;
        $branchIds = $input['branchIds'] ?? null;
        // Explicit tenant filter — REQUIRED when build() runs outside an HTTP
        // request (queued jobs), where the Operation BelongsToTenant global scope
        // is inert and would otherwise sum operations across every company.
        $this->companyId = $input['companyId'] ?? null;

        $data = match ($key) {
            'pl' => $this->profitLoss($from, $to, $branchIds),
            'sales-channel' => $this->salesByChannel($from, $to, $branchIds),
            'smart-compare' => $this->smartCompare($from, $to, $branchIds),
            'profit-cash' => $this->profitCash($from, $to, $branchIds),
            'breakeven' => $this->breakeven($from, $to, $branchIds),
            'op-profit' => $this->operatingProfit($from, $to, $branchIds),
            'menu-eng' => $this->menuEngineering($from, $to, $branchIds),
            default => $this->profitLoss($from, $to, $branchIds),
        };

        return [
            'reportKey' => $key,
            'period' => ['from' => $from, 'to' => $to],
            'data' => $data,
        ];
    }

    /** Tenant filter set by build() for the current report (null = rely on the global scope). */
    private ?string $companyId = null;

    private function scope(?string $from, ?string $to, ?array $branchIds)
    {
        $q = Operation::query();
        if ($this->companyId !== null) {
            $q->where('company_id', $this->companyId);
        }
        if ($from) {
            $q->where('operation_date', '>=', $from);
        }
        if ($to) {
            $q->where('operation_date', '<=', $to);
        }
        if ($branchIds) {
            $q->whereIn('branch_id', $branchIds);
        }

        return $q;
    }

    private function sum($scope, string $module): int
    {
        return (int) (clone $scope)->where('module_key', $module)->sum('amount');
    }

    /** @return array<string, mixed> */
    private function profitLoss(?string $from, ?string $to, ?array $branchIds): array
    {
        $s = $this->scope($from, $to, $branchIds);
        $income = $this->sum($s, 'sales');
        $cogs = $this->sum($s, 'purchases');
        $opex = $this->sum($s, 'expenses');
        $waste = $this->sum($s, 'waste');
        $gross = $income - $cogs;
        $net = $gross - $opex - $waste;

        return [
            'income' => $income,
            'costOfGoodsSold' => $cogs,
            'grossProfit' => $gross,
            'operatingExpenses' => $opex,
            'waste' => $waste,
            'netProfit' => $net,
            'grossMarginPct' => $this->pct($gross, $income),
            'netMarginPct' => $this->pct($net, $income),
            'lines' => [
                ['key' => 'income', 'labelAr' => 'الإيرادات', 'amount' => $income],
                ['key' => 'cogs', 'labelAr' => 'تكلفة البضاعة', 'amount' => -$cogs],
                ['key' => 'opex', 'labelAr' => 'مصاريف تشغيلية', 'amount' => -$opex],
                ['key' => 'waste', 'labelAr' => 'الهدر', 'amount' => -$waste],
                ['key' => 'net', 'labelAr' => 'صافي الربح', 'amount' => $net],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function salesByChannel(?string $from, ?string $to, ?array $branchIds): array
    {
        $ops = (clone $this->scope($from, $to, $branchIds))->where('module_key', 'sales')->get(['amount', 'payload']);
        $channels = ['cash' => 0, 'bank' => 0, 'delivery' => 0, 'unclassified' => 0];

        foreach ($ops as $op) {
            $p = $op->payload ?? [];
            $cash = (int) ($p['cash_amount'] ?? $p['cashAmount'] ?? 0);
            $bank = (int) ($p['bank_amount'] ?? $p['bankAmount'] ?? 0);
            $delivery = 0;
            foreach ((array) ($p['delivery_apps'] ?? $p['deliveryApps'] ?? []) as $d) {
                $delivery += (int) (is_array($d) ? ($d['amount'] ?? 0) : $d);
            }
            $classified = $cash + $bank + $delivery;
            $channels['cash'] += $cash;
            $channels['bank'] += $bank;
            $channels['delivery'] += $delivery;
            if ($classified === 0) {
                $channels['unclassified'] += (int) $op->amount;
            }
        }

        $total = array_sum($channels);
        $labels = ['cash' => 'نقدي', 'bank' => 'شبكة/بنك', 'delivery' => 'تطبيقات التوصيل', 'unclassified' => 'غير مصنف'];

        return [
            'total' => $total,
            'channels' => array_values(array_map(fn ($k) => [
                'channel' => $k,
                'labelAr' => $labels[$k],
                'amount' => $channels[$k],
                'pct' => $this->pct($channels[$k], $total),
            ], array_keys($channels))),
        ];
    }

    /** @return array<string, mixed> */
    private function smartCompare(?string $from, ?string $to, ?array $branchIds): array
    {
        $current = $this->periodTotals($from, $to, $branchIds);

        $priorFrom = $priorTo = null;
        if ($from && $to) {
            $f = Carbon::parse($from);
            $t = Carbon::parse($to);
            $lenDays = max(1, $f->diffInDays($t) + 1);
            $priorTo = $f->copy()->subDay()->toDateString();
            $priorFrom = $f->copy()->subDays($lenDays)->toDateString();
        }
        $prior = $this->periodTotals($priorFrom, $priorTo, $branchIds);

        return [
            'current' => $current,
            'prior' => $prior,
            'deltas' => [
                'sales' => $this->delta($current['sales'], $prior['sales']),
                'expenses' => $this->delta($current['expenses'], $prior['expenses']),
                'netProfit' => $this->delta($current['netProfit'], $prior['netProfit']),
            ],
        ];
    }

    /** @return array<string, int> */
    private function periodTotals(?string $from, ?string $to, ?array $branchIds): array
    {
        $s = $this->scope($from, $to, $branchIds);
        $sales = $this->sum($s, 'sales');
        $expenses = $this->sum($s, 'expenses');
        $purchases = $this->sum($s, 'purchases');

        return ['sales' => $sales, 'expenses' => $expenses, 'purchases' => $purchases, 'netProfit' => $sales - $expenses - $purchases];
    }

    /** @return array<string, mixed> */
    private function profitCash(?string $from, ?string $to, ?array $branchIds): array
    {
        $s = $this->scope($from, $to, $branchIds);
        $sales = $this->sum($s, 'sales');
        $expenses = $this->sum($s, 'expenses');
        $purchases = $this->sum($s, 'purchases');
        $net = $sales - $expenses - $purchases;
        $cash = $this->sum($s, 'cash');

        return [
            'netProfit' => $net,
            'cashCollected' => $cash,
            'gap' => $net - $cash,
            'note' => 'الفرق بين الربح المحاسبي والنقدية الفعلية',
        ];
    }

    /** @return array<string, mixed> */
    public function breakeven(?string $from, ?string $to, ?array $branchIds): array
    {
        $s = $this->scope($from, $to, $branchIds);
        $revenue = $this->sum($s, 'sales');
        $fixedCosts = $this->sum($s, 'expenses');
        $variableCosts = $this->sum($s, 'purchases');
        $contributionMargin = $revenue > 0 ? ($revenue - $variableCosts) / $revenue : 0.0;
        $breakevenRevenue = $contributionMargin > 0 ? (int) round($fixedCosts / $contributionMargin) : 0;

        return [
            'revenue' => $revenue,
            'fixedCosts' => $fixedCosts,
            'variableCosts' => $variableCosts,
            'contributionMarginPct' => round($contributionMargin * 100, 2),
            'breakevenRevenue' => $breakevenRevenue,
            'marginOfSafety' => $revenue - $breakevenRevenue,
        ];
    }

    /** @return array<string, mixed> */
    private function operatingProfit(?string $from, ?string $to, ?array $branchIds): array
    {
        $s = $this->scope($from, $to, $branchIds);
        $revenue = $this->sum($s, 'sales');
        $cogs = $this->sum($s, 'purchases');
        $opex = $this->sum($s, 'expenses');
        $operating = $revenue - $cogs - $opex;

        return [
            'revenue' => $revenue,
            'cogs' => $cogs,
            'operatingExpenses' => $opex,
            'operatingProfit' => $operating,
            'operatingMarginPct' => $this->pct($operating, $revenue),
        ];
    }

    /**
     * §7.5 supplier performance — purchase spend + order count per supplier,
     * ranked by spend. Reads the purchases operations' `payload.supplierId`.
     *
     * @return array<string, mixed>
     */
    public function supplierPerformance(?string $from, ?string $to, ?array $branchIds): array
    {
        $ops = (clone $this->scope($from, $to, $branchIds))->where('module_key', 'purchases')->get(['amount', 'payload']);
        $agg = [];
        foreach ($ops as $op) {
            $sid = $op->payload['supplierId'] ?? null;
            if ($sid === null) {
                continue;
            }
            $agg[$sid] ??= ['supplierId' => $sid, 'orderCount' => 0, 'totalHalalas' => 0];
            $agg[$sid]['orderCount']++;
            $agg[$sid]['totalHalalas'] += (int) $op->amount;
        }

        $names = AsabSupplier::whereIn('id', array_keys($agg))->pluck('name', 'id');
        $suppliers = array_map(function ($row) use ($names) {
            $row['supplierName'] = $names[$row['supplierId']] ?? null;

            return $row;
        }, array_values($agg));
        usort($suppliers, fn ($a, $b) => $b['totalHalalas'] <=> $a['totalHalalas']);

        return ['suppliers' => $suppliers];
    }

    /** @return array<string, mixed> */
    public function menuEngineering(?string $from, ?string $to, ?array $branchIds): array
    {
        $ops = (clone $this->scope($from, $to, $branchIds))->where('module_key', 'sales')->get(['payload']);
        $items = [];
        foreach ($ops as $op) {
            foreach ((array) (($op->payload['items'] ?? $op->payload['products'] ?? [])) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = $item['name'] ?? $item['product'] ?? 'unknown';
                $qty = (int) ($item['qty'] ?? $item['quantity'] ?? 0);
                $revenue = (int) ($item['revenue'] ?? $item['amount'] ?? 0);
                $items[$name] ??= ['name' => $name, 'qty' => 0, 'revenue' => 0];
                $items[$name]['qty'] += $qty;
                $items[$name]['revenue'] += $revenue;
            }
        }
        usort($items, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'items' => array_values($items),
            'note' => $items ? null : 'لا توجد تفاصيل أصناف في سجلات المبيعات للفترة المحددة',
        ];
    }

    private function pct(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : 0.0;
    }

    /** @return array{abs:int, pct:float} */
    private function delta(int $current, int $prior): array
    {
        return ['abs' => $current - $prior, 'pct' => $prior !== 0 ? round(($current - $prior) / abs($prior) * 100, 2) : 0.0];
    }
}
