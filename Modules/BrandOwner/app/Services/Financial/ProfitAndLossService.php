<?php

namespace Modules\BrandOwner\Services\Financial;

/**
 * Profit & Loss statement builder.
 *
 * Reads every figure through the shared FinancialDataService so the numbers here
 * match all other Brand Owner financial reports exactly. Returns plain PHP arrays
 * whose keys map 1:1 onto the response JSON (no transformers).
 */
class ProfitAndLossService
{
    public function __construct(
        private readonly FinancialDataService $data,
        private readonly FinancialReportExporter $exporter,
    ) {}

    /**
     * Build the full P&L payload for a branch + period.
     *
     * @return array{
     *   year: int, month: int, branch_id: ?string, branch_name: ?string,
     *   summary: array{total_revenue: float, total_expenses: float, net_profit: float, profit_margin_percentage: float, is_profit: bool},
     *   chart: array{turnover_total: float, direct_cost_total: float, gross_profit: float, grand_total_cost: float, profitability_amount: float, g_and_a_expenses: float, other_income_expenses: float, net_profit_and_loss: float}
     * }
     */
    public function build(?int $year, ?int $month, ?string $branchId): array
    {
        $period = $this->data->resolvePeriod($year, $month);
        $branch = $this->data->resolveBranchRef($branchId);
        $pnl = $this->data->profitAndLoss($branch['id'], $period['start'], $period['end']);

        return [
            'year' => $period['year'],
            'month' => $period['month'],
            'branch_id' => $branch['id'],
            'branch_name' => $branch['name'],
            'summary' => [
                'total_revenue' => $pnl['turnover'],
                'total_expenses' => $pnl['total_cost'],
                'net_profit' => $pnl['net_profit'],
                'profit_margin_percentage' => $pnl['profit_margin'],
                'is_profit' => $pnl['is_profit'],
            ],
            'chart' => [
                'turnover_total' => $pnl['turnover'],
                'direct_cost_total' => $pnl['direct_cost'],
                'gross_profit' => $pnl['gross_profit'],
                'grand_total_cost' => $pnl['total_cost'],
                // Per the doc waterfall: turnover − grand_total_cost = profitability.
                'profitability_amount' => $pnl['net_profit'],
                'g_and_a_expenses' => $pnl['operating_expenses'],
                'other_income_expenses' => 0.0,
                'net_profit_and_loss' => $pnl['net_profit'],
            ],
        ];
    }

    /**
     * Export the P&L statement to a file and return {file_url}.
     *
     * @return array{file_url: string}
     */
    public function export(
        \Illuminate\Database\Eloquent\Model $owner,
        ?int $year,
        ?int $month,
        ?string $branchId,
        string $formatType
    ): array {
        $payload = $this->build($year, $month, $branchId);

        return $this->exporter->export(
            $owner,
            'profit_and_loss',
            $this->title($payload['year'], $payload['month']),
            $this->sections($payload),
            $formatType,
            [
                'year' => $payload['year'],
                'month' => $payload['month'],
                'branch_id' => $payload['branch_id'],
            ],
        );
    }

    /**
     * Email the P&L statement to the given address.
     */
    public function email(
        ?int $year,
        ?int $month,
        ?string $branchId,
        string $email
    ): void {
        $payload = $this->build($year, $month, $branchId);

        $this->exporter->email(
            'profit_and_loss',
            $this->title($payload['year'], $payload['month']),
            $this->sections($payload),
            $email,
        );
    }

    private function title(int $year, int $month): string
    {
        return 'Profit & Loss Statement - '.$this->data->monthName($month).' '.$year;
    }

    /**
     * Presentation-agnostic sections built from the SAME computed payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function sections(array $payload): array
    {
        $summary = $payload['summary'];
        $chart = $payload['chart'];

        return [
            [
                'heading' => 'Summary',
                'rows' => [
                    ['label' => 'Total Revenue', 'value' => $summary['total_revenue']],
                    ['label' => 'Total Expenses', 'value' => $summary['total_expenses']],
                    ['label' => 'Net Profit', 'value' => $summary['net_profit']],
                    ['label' => 'Profit Margin (%)', 'value' => $summary['profit_margin_percentage']],
                    ['label' => 'Is Profit', 'value' => $summary['is_profit'] ? 'Yes' : 'No'],
                ],
            ],
            [
                'heading' => 'Chart',
                'rows' => [
                    ['label' => 'Turnover Total', 'value' => $chart['turnover_total']],
                    ['label' => 'Direct Cost Total', 'value' => $chart['direct_cost_total']],
                    ['label' => 'Gross Profit', 'value' => $chart['gross_profit']],
                    ['label' => 'Grand Total Cost', 'value' => $chart['grand_total_cost']],
                    ['label' => 'Profitability Amount', 'value' => $chart['profitability_amount']],
                    ['label' => 'G&A Expenses', 'value' => $chart['g_and_a_expenses']],
                    ['label' => 'Other Income / Expenses', 'value' => $chart['other_income_expenses']],
                    ['label' => 'Net Profit & Loss', 'value' => $chart['net_profit_and_loss']],
                ],
            ],
        ];
    }
}
