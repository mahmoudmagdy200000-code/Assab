<?php

namespace Modules\BrandOwner\Services\Financial;

use Illuminate\Database\Eloquent\Model;

/**
 * Smart Comparison report.
 *
 * Compares a branch's turnover either month-over-month (type=month) or against a
 * second branch for the same period (type=branch). Every figure is sourced from
 * the shared FinancialDataService so the numbers match every other report.
 */
class SmartComparisonService
{
    public function __construct(
        private readonly FinancialDataService $data,
        private readonly FinancialReportExporter $exporter,
    ) {}

    /**
     * Build the full comparison payload.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function comparison(array $input): array
    {
        $type = (string) $input['type'];

        // branch_id is optional: fall back to the first branch (DB default) so the
        // comparison always renders a real branch's figures.
        $branch = $this->data->resolveBranchRef($input['branch_id'] ?? null);
        $branchId = $branch['id'];
        $comparedBranchId = $input['compared_branch_id'] ?? null;

        $period = $this->data->resolvePeriod(
            isset($input['year']) ? (int) $input['year'] : null,
            isset($input['month']) ? (int) $input['month'] : null,
        );

        $pnl = $this->data->profitAndLoss($branchId, $period['start'], $period['end']);

        $summary = [
            'total_sales' => (float) $pnl['turnover'],
            'total_profitability' => (float) $pnl['gross_profit'],
            'total_profitability_percentage' => $this->data->ratio(
                (float) $pnl['gross_profit'],
                (float) $pnl['turnover'],
            ),
            'net_profit_percentage' => (float) $pnl['profit_margin'],
            'profit_margin_percentage' => (float) $pnl['profit_margin'],
        ];

        $mainValue = (float) $pnl['turnover'];

        if ($type === 'branch') {
            $compared = $period;

            // compared_branch_id is optional too: default to the next branch so the
            // report never silently aggregates every branch into the compared value.
            $comparedBranch = $this->data->resolveComparedBranchRef($comparedBranchId, $branchId);
            $comparedBranchId = $comparedBranch['id'];
            $comparedBranchName = $comparedBranch['name'];

            $comparedValue = $comparedBranchId !== null
                ? $this->data->totalRevenue($comparedBranchId, $period['start'], $period['end'])
                : 0.0;
        } else {
            $comparedGiven = ! empty($input['compared_year']) && ! empty($input['compared_month']);

            if ($comparedGiven) {
                $compared = $this->data->resolvePeriod(
                    (int) $input['compared_year'],
                    (int) $input['compared_month'],
                );
            } else {
                $prev = $this->data->previousMonth($period['year'], $period['month']);
                $compared = $this->data->resolvePeriod($prev['year'], $prev['month']);
            }

            $comparedValue = $this->data->totalRevenue(
                $branchId,
                $compared['start'],
                $compared['end'],
            );
            $comparedBranchId = null;
            $comparedBranchName = null;
        }

        return [
            'year' => $period['year'],
            'month' => $period['month'],
            'compared_year' => $compared['year'],
            'compared_month' => $compared['month'],
            'month_name' => $period['month_name'],
            'compared_month_name' => $compared['month_name'],
            'type' => $type,
            'branch_id' => $branch['id'],
            'branch_name' => $branch['name'],
            'compared_branch_id' => $comparedBranchId,
            'compared_branch_name' => $comparedBranchName,
            'summary' => $summary,
            'chart_data' => [
                'main_month' => $period['month'],
                'main_year' => $period['year'],
                'main_value' => $mainValue,
                'compared_month' => $compared['month'],
                'compared_year' => $compared['year'],
                'compared_value' => (float) $comparedValue,
            ],
        ];
    }

    /**
     * Render the comparison to a file and return {file_url}.
     *
     * @param  array<string, mixed>  $input
     * @return array{file_url: string}
     */
    public function export(Model $owner, array $input): array
    {
        $result = $this->comparison($input);

        return $this->exporter->export(
            $owner,
            'smart_comparison',
            $this->title($result),
            $this->sections($result),
            (string) $input['format_type'],
            $input,
        );
    }

    /**
     * Email the comparison report to the given address.
     *
     * @param  array<string, mixed>  $input
     */
    public function email(array $input): void
    {
        $result = $this->comparison($input);

        $this->exporter->email(
            'smart_comparison',
            $this->title($result),
            $this->sections($result),
            (string) $input['email'],
            'PDF',
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function title(array $result): string
    {
        return 'Smart Comparison - '.$result['month_name'].' '.$result['year'];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private function sections(array $result): array
    {
        $summary = $result['summary'];
        $chart = $result['chart_data'];

        $mainLabel = $result['branch_name'] ?? 'Main';
        $comparedLabel = $result['type'] === 'branch'
            ? ($result['compared_branch_name'] ?? 'Compared branch')
            : ($result['compared_month_name'].' '.$result['compared_year']);

        return [
            [
                'heading' => 'Summary',
                'rows' => [
                    ['label' => 'Total Sales', 'value' => $summary['total_sales']],
                    ['label' => 'Total Profitability', 'value' => $summary['total_profitability']],
                    ['label' => 'Total Profitability %', 'value' => $summary['total_profitability_percentage']],
                    ['label' => 'Net Profit %', 'value' => $summary['net_profit_percentage']],
                    ['label' => 'Profit Margin %', 'value' => $summary['profit_margin_percentage']],
                ],
            ],
            [
                'heading' => 'Comparison',
                'table' => [
                    'headers' => ['Series', 'Value'],
                    'rows' => [
                        [$mainLabel.' ('.$result['month_name'].' '.$result['year'].')', $chart['main_value']],
                        [$comparedLabel, $chart['compared_value']],
                    ],
                ],
            ],
        ];
    }
}
