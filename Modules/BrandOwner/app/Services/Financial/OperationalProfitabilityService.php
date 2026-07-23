<?php

namespace Modules\BrandOwner\Services\Financial;

/**
 * Operational Profitability report — current month, whole brand (branchId = null).
 *
 * Every figure is computed through FinancialDataService so numbers match the rest
 * of the Brand Owner financial suite. Returns plain snake_case arrays; the exporter
 * receives the same computed data reshaped into presentation sections.
 */
class OperationalProfitabilityService
{
    public function __construct(
        private readonly FinancialDataService $data,
        private readonly FinancialReportExporter $exporter,
    ) {}

    /**
     * Full report payload.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return $this->compute();
    }

    /**
     * Export the report to a file via the shared exporter.
     *
     * @return array{file_url: string}
     */
    public function export(\Illuminate\Database\Eloquent\Model $owner, string $formatType): array
    {
        $computed = $this->compute();

        return $this->exporter->export(
            $owner,
            'operational_profitability',
            'Operational Profitability Report',
            $this->sections($computed),
            $formatType,
        );
    }

    /**
     * Email the report to the given address via the shared exporter.
     */
    public function email(string $email, string $formatType = 'PDF'): void
    {
        $computed = $this->compute();

        $this->exporter->email(
            'operational_profitability',
            'Operational Profitability Report',
            $this->sections($computed),
            $email,
            $formatType,
        );
    }

    // ----------------------------------------------------------------
    // Calculation core
    // ----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function compute(): array
    {
        $period = $this->data->resolvePeriod(null, null);
        $start = $period['start'];
        $end = $period['end'];

        $prev = $this->data->previousMonth($period['year'], $period['month']);
        $prevPeriod = $this->data->resolvePeriod($prev['year'], $prev['month']);
        $prevStart = $prevPeriod['start'];
        $prevEnd = $prevPeriod['end'];

        $pnl = $this->data->profitAndLoss(null, $start, $end);
        $pnlPrev = $this->data->profitAndLoss(null, $prevStart, $prevEnd);

        $turnover = $pnl['turnover'];
        $operatingProfit = $pnl['net_profit'];
        $opChange = $this->data->percentageChange($pnl['net_profit'], $pnlPrev['net_profit']);

        $ebitda = round($pnl['net_profit'] + $this->data->expenseVat(null, $start, $end), 2);
        $ebitdaPrev = $pnlPrev['net_profit'] + $this->data->expenseVat(null, $prevStart, $prevEnd);
        $ebitdaChange = $this->data->percentageChange($ebitda, $ebitdaPrev);

        $gpm = $this->data->ratio($pnl['gross_profit'], $turnover);
        $gpmPrev = $this->data->ratio($pnlPrev['gross_profit'], $pnlPrev['turnover']);
        $gpmChange = round($gpm - $gpmPrev, 2);

        $ros = $this->data->ratio($pnl['net_profit'], $turnover);
        $rosLabel = $ros >= 20 ? 'Excellent' : ($ros >= 10 ? 'Good' : ($ros >= 0 ? 'Fair' : 'Poor'));

        $emp = $this->data->employeeCount(null);
        $productivity = $emp > 0 ? round($turnover / $emp, 2) : 0.0;
        $prevProductivity = $emp > 0 ? round($pnlPrev['turnover'] / $emp, 2) : 0.0;
        $prodChange = $this->data->percentageChange($productivity, $prevProductivity);

        $fixed = $this->data->fixedCosts(null, $start, $end);
        $labor = $fixed['salaries'];
        $laborPct = round($this->data->ratio($labor, $turnover), 0);

        $inv = $this->data->inventoryValue(null);
        $invTurnover = $inv > 0 ? round($pnl['direct_cost'] / $inv, 2) : 0.0;

        $orders = $this->data->orderCount(null, $start, $end);
        $atv = $orders > 0 ? round($turnover / $orders, 2) : 0.0;

        $score = $ros >= 20 ? 'A+' : ($ros >= 15 ? 'A' : ($ros >= 10 ? 'B+' : ($ros >= 5 ? 'B' : ($ros >= 0 ? 'C' : 'D'))));
        $stars = $ros >= 20 ? 5 : ($ros >= 15 ? 4 : ($ros >= 10 ? 3 : ($ros >= 5 ? 2 : 1)));
        $performance = $ros >= 15 ? 'Excellent' : ($ros >= 8 ? 'Good' : ($ros >= 0 ? 'Fair' : 'Poor'));

        return [
            'metrics' => [
                'overall_performance' => $performance,
                'overall_score' => $score,
                'stars_count' => $stars,
            ],
            'core_indicators' => [
                'operating_profit' => $operatingProfit,
                'operating_profit_change' => $opChange,
                'operating_profit_is_positive' => $opChange >= 0,
                'ebitda' => $ebitda,
                'ebitda_change' => $ebitdaChange,
                'ebitda_is_positive' => $ebitdaChange >= 0,
                'gross_profit_margin' => $gpm,
                'gross_profit_margin_change' => $gpmChange,
                'gross_profit_margin_is_positive' => $gpmChange >= 0,
                'return_on_sales' => $ros,
                'return_on_sales_label' => $rosLabel,
            ],
            'efficiency' => [
                'productivity_per_employee' => $productivity,
                'productivity_per_employee_change' => $prodChange,
                'productivity_is_positive' => $prodChange >= 0,
                'labor_cost' => $labor,
                'labor_cost_label' => $laborPct.'% of revenue',
                'inventory_turnover' => $invTurnover,
                'inventory_turnover_unit' => 'times/year',
                'average_transaction_value' => $atv,
                'space_efficiency' => 0.0,
                'space_efficiency_unit' => 'SAR/m²',
            ],
            'benchmark' => [
                'profit_margin_your_restaurant' => $ros,
                'profit_margin_industry' => 20.0,
                'labor_cost_your_restaurant' => round($this->data->ratio($labor, $turnover), 2),
                'labor_cost_industry' => 22.0,
                'inventory_turnover_your_restaurant' => $invTurnover,
                'inventory_turnover_industry' => 6.0,
            ],
        ];
    }

    /**
     * Reshape the computed payload into exporter sections.
     *
     * @param  array<string, mixed>  $computed
     * @return array<int, array<string, mixed>>
     */
    private function sections(array $computed): array
    {
        $metrics = $computed['metrics'];
        $core = $computed['core_indicators'];
        $eff = $computed['efficiency'];
        $bench = $computed['benchmark'];

        return [
            [
                'heading' => 'Overall Performance',
                'rows' => [
                    ['label' => 'Overall Performance', 'value' => $metrics['overall_performance']],
                    ['label' => 'Overall Score', 'value' => $metrics['overall_score']],
                    ['label' => 'Stars', 'value' => $metrics['stars_count']],
                ],
            ],
            [
                'heading' => 'Core Indicators',
                'rows' => [
                    ['label' => 'Operating Profit', 'value' => $core['operating_profit']],
                    ['label' => 'Operating Profit Change (%)', 'value' => $core['operating_profit_change']],
                    ['label' => 'EBITDA', 'value' => $core['ebitda']],
                    ['label' => 'EBITDA Change (%)', 'value' => $core['ebitda_change']],
                    ['label' => 'Gross Profit Margin (%)', 'value' => $core['gross_profit_margin']],
                    ['label' => 'Gross Profit Margin Change (%)', 'value' => $core['gross_profit_margin_change']],
                    ['label' => 'Return on Sales (%)', 'value' => $core['return_on_sales']],
                    ['label' => 'Return on Sales', 'value' => $core['return_on_sales_label']],
                ],
            ],
            [
                'heading' => 'Efficiency',
                'rows' => [
                    ['label' => 'Productivity per Employee', 'value' => $eff['productivity_per_employee']],
                    ['label' => 'Productivity Change (%)', 'value' => $eff['productivity_per_employee_change']],
                    ['label' => 'Labor Cost', 'value' => $eff['labor_cost']],
                    ['label' => 'Labor Cost Share', 'value' => $eff['labor_cost_label']],
                    ['label' => 'Inventory Turnover', 'value' => $eff['inventory_turnover'].' '.$eff['inventory_turnover_unit']],
                    ['label' => 'Average Transaction Value', 'value' => $eff['average_transaction_value']],
                    ['label' => 'Space Efficiency', 'value' => $eff['space_efficiency'].' '.$eff['space_efficiency_unit']],
                ],
            ],
            [
                'heading' => 'Benchmark',
                'table' => [
                    'headers' => ['Metric', 'Your Restaurant', 'Industry'],
                    'rows' => [
                        ['Profit Margin (%)', $bench['profit_margin_your_restaurant'], $bench['profit_margin_industry']],
                        ['Labor Cost (%)', $bench['labor_cost_your_restaurant'], $bench['labor_cost_industry']],
                        ['Inventory Turnover', $bench['inventory_turnover_your_restaurant'], $bench['inventory_turnover_industry']],
                    ],
                ],
            ],
        ];
    }
}
