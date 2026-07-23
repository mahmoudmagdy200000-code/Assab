<?php

namespace Modules\BrandOwner\Services\Financial;

/**
 * Break-even analysis for a single branch + month.
 *
 * Contribution-margin break-even: how much turnover the branch must generate
 * to cover its fixed costs, plus a safety-status classification of where the
 * branch's actual turnover sits relative to that break-even point.
 *
 * All figures flow through {@see FinancialDataService} so a number is computed
 * identically here and in every other Brand Owner report.
 */
class BreakEvenAnalysisService
{
    public function __construct(
        private readonly FinancialDataService $data,
        private readonly FinancialReportExporter $exporter,
    ) {}

    /**
     * Full break-even payload for a branch + period.
     *
     * @return array<string, mixed>
     */
    public function analyze(?int $year, ?int $month, string $branchId): array
    {
        return $this->compute($year, $month, $branchId);
    }

    /**
     * Compute the report, render it to a file, and return {file_url}.
     *
     * @return array{file_url: string}
     */
    public function export(
        \Illuminate\Database\Eloquent\Model $owner,
        ?int $year,
        ?int $month,
        string $branchId,
        string $formatType
    ): array {
        $payload = $this->compute($year, $month, $branchId);

        return $this->exporter->export(
            $owner,
            'break_even_analysis',
            $this->title($payload),
            $this->buildSections($payload),
            $formatType,
            [
                'year' => $payload['year'],
                'month' => $payload['month'],
                'branch_id' => $branchId,
                'format_type' => $formatType,
            ],
        );
    }

    /**
     * Shared calculation core used by both the read endpoint and the export.
     *
     * @return array<string, mixed>
     */
    private function compute(?int $year, ?int $month, string $branchId): array
    {
        $period = $this->data->resolvePeriod($year, $month);
        $prev = $this->data->previousMonth($period['year'], $period['month']);

        $pnl = $this->data->profitAndLoss($branchId, $period['start'], $period['end']);
        $fixed = $this->data->fixedCosts($branchId, $period['start'], $period['end']);

        $variableCosts = (float) $pnl['direct_cost'];
        $turnover = (float) $pnl['turnover'];

        $contributionRatio = $turnover > 0 ? ($turnover - $variableCosts) / $turnover : 0.0;
        $breakEven = $contributionRatio > 0 ? $fixed['total_fixed'] / $contributionRatio : 0.0;

        $r = $breakEven > 0 ? $turnover / $breakEven : ($turnover > 0 ? 2.0 : 0.0);
        $status = $this->status($r);
        $currentPointPosition = round(min(100, $r * 50), 2);

        $grossProfitMarginPct = $this->data->ratio($turnover - $variableCosts, $turnover);

        return [
            'year' => $period['year'],
            'month' => $period['month'],
            'month_name' => $period['month_name'],
            'previous_month_name' => $prev['month_name'],
            'branch_id' => $branchId,
            'branch_name' => $this->data->branchName($branchId),
            'status' => $status,
            'current_point_position' => $currentPointPosition,
            'metrics' => [
                'current_point_x' => $turnover,
                'current_point_y' => (float) $pnl['net_profit'],
                'fixed_cost_x' => round($breakEven, 2),
                'fixed_cost_y' => 0.0,
            ],
            'fixed_costs' => [
                'rent_and_utilities' => (float) $fixed['rent_and_utilities'],
                'salaries' => (float) $fixed['salaries'],
                'insurance_and_licenses' => (float) $fixed['insurance_and_licenses'],
                'total_fixed' => (float) $fixed['total_fixed'],
            ],
            'contribution_margin' => [
                'gross_profit_margin' => $grossProfitMarginPct,
                'variable_costs' => $variableCosts,
            ],
            'formula' => [
                'fixed_costs' => (float) $fixed['total_fixed'],
                'contribution_margin' => round($contributionRatio, 4),
                'result' => round($breakEven, 2),
            ],
        ];
    }

    private function status(float $r): string
    {
        return match (true) {
            $r >= 1.5 => 'very_safe',
            $r >= 1.15 => 'safe',
            $r >= 0.95 => 'at_break_even',
            $r >= 0.7 => 'risk',
            default => 'high_risk',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function title(array $payload): string
    {
        return sprintf(
            'Break-Even Analysis - %s %d - %s',
            $payload['month_name'],
            $payload['year'],
            $payload['branch_name'] ?? $payload['branch_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function buildSections(array $payload): array
    {
        return [
            [
                'heading' => 'Overview',
                'rows' => [
                    ['label' => 'Branch', 'value' => $payload['branch_name'] ?? $payload['branch_id']],
                    ['label' => 'Period', 'value' => $payload['month_name'].' '.$payload['year']],
                    ['label' => 'Status', 'value' => $payload['status']],
                    ['label' => 'Current Point Position', 'value' => $payload['current_point_position']],
                ],
            ],
            [
                'heading' => 'Fixed Costs',
                'rows' => [
                    ['label' => 'Rent & Utilities', 'value' => $payload['fixed_costs']['rent_and_utilities']],
                    ['label' => 'Salaries', 'value' => $payload['fixed_costs']['salaries']],
                    ['label' => 'Insurance & Licenses', 'value' => $payload['fixed_costs']['insurance_and_licenses']],
                    ['label' => 'Total Fixed', 'value' => $payload['fixed_costs']['total_fixed']],
                ],
            ],
            [
                'heading' => 'Contribution Margin',
                'rows' => [
                    ['label' => 'Gross Profit Margin (%)', 'value' => $payload['contribution_margin']['gross_profit_margin']],
                    ['label' => 'Variable Costs', 'value' => $payload['contribution_margin']['variable_costs']],
                ],
            ],
            [
                'heading' => 'Break-Even Formula',
                'rows' => [
                    ['label' => 'Fixed Costs', 'value' => $payload['formula']['fixed_costs']],
                    ['label' => 'Contribution Margin', 'value' => $payload['formula']['contribution_margin']],
                    ['label' => 'Break-Even Result', 'value' => $payload['formula']['result']],
                ],
            ],
            [
                'heading' => 'Break-Even Chart Metrics',
                'rows' => [
                    ['label' => 'Current Point X (Turnover)', 'value' => $payload['metrics']['current_point_x']],
                    ['label' => 'Current Point Y (Net Profit)', 'value' => $payload['metrics']['current_point_y']],
                    ['label' => 'Fixed Cost X (Break-Even)', 'value' => $payload['metrics']['fixed_cost_x']],
                    ['label' => 'Fixed Cost Y', 'value' => $payload['metrics']['fixed_cost_y']],
                ],
            ],
        ];
    }
}
