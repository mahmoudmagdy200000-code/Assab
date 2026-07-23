<?php

namespace Modules\BrandOwner\Services\Financial;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Sales-channel financial reports (ANALYSIS + LEVEL2) for the Brand Owner world.
 *
 * A "sales channel" is an aggregator: channel sales, commission_rate and logo all
 * live on the aggregator, exposed through FinancialDataService::channelSales().
 * Every figure is computed through the shared FinancialDataService so the numbers
 * match the rest of the financial suite exactly. All returned values are plain
 * PHP scalars/arrays (no Eloquent models, no null numerics).
 */
class SalesChannelService
{
    public function __construct(
        private readonly FinancialDataService $data,
    ) {}

    // ----------------------------------------------------------------
    // ANALYSIS
    // ----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function analysis(array $params): array
    {
        [$period, $compared] = $this->periods($params);
        $branchId = $this->branchId($params);

        $channels = $this->data->channelSales($branchId, $period['start'], $period['end']);
        $pnl = $this->data->profitAndLoss($branchId, $period['start'], $period['end']);

        $mainValue = (float) $channels->sum('amount');
        $comparedValue = (float) $this->data
            ->channelSales($branchId, $compared['start'], $compared['end'])
            ->sum('amount');

        $totalProfitability = (float) $channels->sum(
            fn (array $c) => $this->profitabilityAmount($c)
        );

        return [
            'year' => $period['year'],
            'month' => $period['month'],
            'compared_year' => $compared['year'],
            'compared_month' => $compared['month'],
            'month_name' => $period['month_name'],
            'branch_id' => $branchId,
            'branch_name' => (string) ($this->data->branchName($branchId) ?? ''),
            'summary' => [
                'total_sales' => $mainValue,
                'total_profitability' => $totalProfitability,
                'total_profitability_percentage' => $this->data->ratio($totalProfitability, $mainValue),
                'net_profit_percentage' => (float) $pnl['profit_margin'],
                'profit_margin_percentage' => (float) $pnl['profit_margin'],
            ],
            'chart_data' => [
                'main_month' => $period['month'],
                'main_year' => $period['year'],
                'main_value' => $mainValue,
                'compared_month' => $compared['month'],
                'compared_year' => $compared['year'],
                'compared_value' => $comparedValue,
            ],
        ];
    }

    /**
     * Title + sections for exporting/emailing the analysis report.
     *
     * @param  array<string, mixed>  $params
     * @return array{title: string, sections: array<int, array<string, mixed>>}
     */
    public function analysisReport(array $params): array
    {
        [$period, $compared] = $this->periods($params);
        $branchId = $this->branchId($params);

        $channels = $this->data->channelSales($branchId, $period['start'], $period['end']);
        $pnl = $this->data->profitAndLoss($branchId, $period['start'], $period['end']);

        $mainValue = (float) $channels->sum('amount');
        $totalProfitability = (float) $channels->sum(
            fn (array $c) => $this->profitabilityAmount($c)
        );

        $sections = [
            [
                'heading' => 'Summary',
                'rows' => [
                    ['label' => 'Total Sales', 'value' => $mainValue],
                    ['label' => 'Total Profitability', 'value' => $totalProfitability],
                    ['label' => 'Total Profitability %', 'value' => $this->data->ratio($totalProfitability, $mainValue)],
                    ['label' => 'Net Profit %', 'value' => (float) $pnl['profit_margin']],
                    ['label' => 'Profit Margin %', 'value' => (float) $pnl['profit_margin']],
                ],
            ],
            $this->channelsTable($channels),
        ];

        return [
            'title' => 'Sales Channel Analysis - '.$period['month_name'].' '.$period['year'],
            'sections' => $sections,
        ];
    }

    // ----------------------------------------------------------------
    // LEVEL 2
    // ----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function level2(array $params): array
    {
        [$period, $compared] = $this->periods($params);
        $branchId = $this->branchId($params);

        $channels = $this->data->channelSales($branchId, $period['start'], $period['end']);
        $comparedByAggregator = $this->data
            ->channelSales($branchId, $compared['start'], $compared['end'])
            ->keyBy('aggregator_id');

        $items = $channels->map(function (array $channel) use ($comparedByAggregator) {
            $amount = (float) $channel['amount'];
            $prevAmount = (float) ($comparedByAggregator[$channel['aggregator_id']]['amount'] ?? 0.0);
            $change = $this->data->percentageChange($amount, $prevAmount);
            $orderCount = (int) $channel['order_count'];
            $profitabilityAmount = $this->profitabilityAmount($channel);

            return [
                'id' => $channel['aggregator_id'],
                'name' => (string) $channel['name'],
                'image' => (string) ($channel['logo_url'] ?? ''),
                'sales_amount' => $amount,
                'percentage_change' => $change,
                'is_positive' => $change >= 0,
                'comparison_text' => ($change >= 0 ? '+' : '').number_format($change, 1).'% vs last month',
                'commission_amount' => $this->commissionAmount($channel),
                'commission_percentage' => (float) $channel['commission_rate'],
                'profitability_amount' => $profitabilityAmount,
                'profitability_percentage' => $this->data->ratio($profitabilityAmount, $amount),
                'order_count' => $orderCount,
                'average_order_value' => $orderCount > 0 ? round($amount / $orderCount, 2) : 0.0,
            ];
        })->all();

        return [
            'year' => $period['year'],
            'month' => $period['month'],
            'compared_year' => $compared['year'],
            'compared_month' => $compared['month'],
            'month_name' => $period['month_name'],
            'total_count' => $channels->count(),
            'items' => $items,
            'chart_data' => $this->level2Chart($branchId, $period),
        ];
    }

    /**
     * Title + sections for exporting the level-2 report.
     *
     * @param  array<string, mixed>  $params
     * @return array{title: string, sections: array<int, array<string, mixed>>}
     */
    public function level2Report(array $params): array
    {
        [$period] = $this->periods($params);
        $branchId = $this->branchId($params);

        $channels = $this->data->channelSales($branchId, $period['start'], $period['end']);
        $totalSales = (float) $channels->sum('amount');

        $sections = [
            [
                'heading' => 'Summary',
                'rows' => [
                    ['label' => 'Total Channels', 'value' => $channels->count()],
                    ['label' => 'Total Sales', 'value' => $totalSales],
                ],
            ],
            $this->channelsTable($channels),
        ];

        return [
            'title' => 'Sales Channel Level 2 - '.$period['month_name'].' '.$period['year'],
            'sections' => $sections,
        ];
    }

    // ----------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------

    /**
     * Resolve the main period and its comparison period.
     *
     * @param  array<string, mixed>  $params
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function periods(array $params): array
    {
        $period = $this->data->resolvePeriod(
            $this->intOrNull($params['year'] ?? null),
            $this->intOrNull($params['month'] ?? null),
        );

        $comparedYear = $this->intOrNull($params['compared_year'] ?? null);
        $comparedMonth = $this->intOrNull($params['compared_month'] ?? null);

        if ($comparedYear !== null && $comparedMonth !== null) {
            $compared = $this->data->resolvePeriod($comparedYear, $comparedMonth);
        } else {
            $previous = $this->data->previousMonth($period['year'], $period['month']);
            $compared = $this->data->resolvePeriod($previous['year'], $previous['month']);
        }

        return [$period, $compared];
    }

    /**
     * Trailing six-month channel-sales totals ending at the given period.
     *
     * @param  array<string, mixed>  $period
     * @return array<int, array{month: string, value: float}>
     */
    private function level2Chart(?string $branchId, array $period): array
    {
        /** @var Carbon $start */
        $start = $period['start'];
        $chart = [];

        for ($offset = 5; $offset >= 0; $offset--) {
            $monthStart = $start->copy()->subMonthsNoOverflow($offset)->startOfMonth();
            $monthPeriod = $this->data->resolvePeriod((int) $monthStart->year, (int) $monthStart->month);
            $total = (float) $this->data
                ->channelSales($branchId, $monthPeriod['start'], $monthPeriod['end'])
                ->sum('amount');

            $chart[] = [
                'month' => $monthStart->format('Y-m-d\TH:i:s').'.000Z',
                'value' => $total,
            ];
        }

        return $chart;
    }

    /**
     * Channel table section: Channel | Sales | Commission | Profitability.
     *
     * @param  Collection<int, array<string, mixed>>  $channels
     * @return array<string, mixed>
     */
    private function channelsTable(Collection $channels): array
    {
        return [
            'heading' => 'Channels',
            'table' => [
                'headers' => ['Channel', 'Sales', 'Commission', 'Profitability'],
                'rows' => $channels->map(fn (array $c) => [
                    (string) $c['name'],
                    (float) $c['amount'],
                    $this->commissionAmount($c),
                    $this->profitabilityAmount($c),
                ])->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    private function commissionAmount(array $channel): float
    {
        return round(((float) $channel['amount']) * ((float) $channel['commission_rate']) / 100, 2);
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    private function profitabilityAmount(array $channel): float
    {
        return round(((float) $channel['amount']) - $this->commissionAmount($channel), 2);
    }

    /**
     * The effective branch id for a request: the supplied branch when a valid id
     * is given, otherwise the first branch from the database as the default (the
     * user picks a branch via the filter; before they do we show a real one, not
     * an all-branches aggregate).
     *
     * @param  array<string, mixed>  $params
     */
    private function branchId(array $params): ?string
    {
        $branchId = $params['branch_id'] ?? null;
        $branchId = $branchId !== null && $branchId !== '' ? (string) $branchId : null;

        return $this->data->resolveBranchRef($branchId)['id'];
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
