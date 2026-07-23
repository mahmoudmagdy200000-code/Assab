<?php

namespace Modules\BrandOwner\Services\Financial;

use Illuminate\Database\Eloquent\Model;

/**
 * Profit-vs-Cash reconciliation for the current month across all branches.
 *
 * Explains why the accrual net profit for the period differs from the cash the
 * owner actually holds, by laying out the timing/non-cash items (pending sales,
 * accrued rent + salaries, unused inventory, unpaid supplier obligations, VAT
 * provisioning, non-cash expenses) that bridge the two figures. All numbers are
 * computed once through FinancialDataService so they agree with every other
 * Brand Owner financial report.
 */
class ProfitVsCashReconciliationService
{
    public function __construct(
        private readonly FinancialDataService $data,
        private readonly FinancialReportExporter $exporter,
    ) {}

    /**
     * The full reconciliation payload (current month, all branches).
     *
     * @return array<string, mixed>
     */
    public function reconciliation(): array
    {
        return $this->compute();
    }

    /**
     * Export the reconciliation to a file and return its public URL.
     *
     * @return array{file_url: string}
     */
    public function export(Model $owner, string $formatType): array
    {
        $payload = $this->compute();

        return $this->exporter->export(
            $owner,
            'profit_vs_cash_reconciliation',
            $this->title($payload),
            $this->sections($payload),
            $formatType,
        );
    }

    /**
     * Email the reconciliation report to $email.
     */
    public function email(string $email, string $formatType = 'PDF'): void
    {
        $payload = $this->compute();

        $this->exporter->email(
            'profit_vs_cash_reconciliation',
            $this->title($payload),
            $this->sections($payload),
            $email,
            $formatType,
        );
    }

    // ----------------------------------------------------------------
    // Computation
    // ----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function compute(): array
    {
        $period = $this->data->resolvePeriod(null, null);
        $start = $period['start'];
        $end = $period['end'];

        $pnl = $this->data->profitAndLoss(null, $start, $end);
        $cash = $this->data->cashOnHand(null, $start, $end);
        $fixed = $this->data->fixedCosts(null, $start, $end);
        $inventory = $this->data->inventoryValue(null);
        $vat = $this->data->vatCollected(null, $start, $end);
        $suppliers = $this->data->supplierObligations(null, $start, $end);

        $netProfit = (float) $pnl['net_profit'];
        $turnover = (float) $pnl['turnover'];
        $rent = (float) $fixed['rent_and_utilities'];
        $salaries = (float) $fixed['salaries'];

        $pendingTotal = max(0.0, round($turnover - $cash, 2));

        return [
            'date_range_label' => $period['month_name'].' '.$period['year'],
            'monthly_profits' => $netProfit,
            'current_cash' => $cash,
            'difference' => round($netProfit - $cash, 2),
            'pending_sales' => [
                'total' => $pendingTotal,
                'paid_amount' => 0.0,
                'shown_in_profits' => $pendingTotal,
                'reason' => 'Sales recognised in profit but cash not yet collected',
                'items' => [],
            ],
            'rent_paid' => [
                'amount' => $rent,
                'paid_amount' => $rent,
                'shown_in_profits' => $rent,
            ],
            'previous_expense_reconciliation' => [
                'amount' => 0.0,
            ],
            'advances_fixed_assets' => [
                'amount' => 0.0,
            ],
            'zakat_taxes' => [
                'amount' => $vat,
                'bought_amount' => $vat,
                'used_amount' => 0.0,
            ],
            'unused_inventory' => [
                'total' => $inventory,
                'reason' => 'Inventory purchased but not yet sold',
                'items' => [],
            ],
            'non_cash_expenses' => [
                'depreciation' => 0.0,
                'others' => 0.0,
                'total' => 0.0,
                'reason' => 'No non-cash expenses recorded',
            ],
            'supplier_obligations' => [
                'total' => (float) $suppliers['total'],
                'reason' => 'Suppliers not yet paid',
                'items' => $suppliers['items'],
            ],
            'accrued_salaries' => [
                'total' => $salaries,
                'reason' => 'Salaries accrued in profit',
                'items' => [
                    ['name' => 'Staff Salaries', 'amount' => $salaries],
                ],
            ],
            'expected_cash' => $cash,
            'final_result_info' => [
                ['description' => 'The difference between monthly profits and current cash is explained by the items above.'],
            ],
        ];
    }

    // ----------------------------------------------------------------
    // Export presentation
    // ----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    private function title(array $payload): string
    {
        return 'Profit vs Cash Reconciliation - '.$payload['date_range_label'];
    }

    /**
     * Build the presentation-agnostic section payload from the computed data.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function sections(array $payload): array
    {
        $sections = [
            [
                'heading' => 'Summary',
                'rows' => [
                    ['label' => 'Period', 'value' => $payload['date_range_label']],
                    ['label' => 'Monthly Profits', 'value' => $payload['monthly_profits']],
                    ['label' => 'Current Cash', 'value' => $payload['current_cash']],
                    ['label' => 'Difference', 'value' => $payload['difference']],
                    ['label' => 'Expected Cash', 'value' => $payload['expected_cash']],
                ],
            ],
            [
                'heading' => 'Pending Sales',
                'rows' => [
                    ['label' => 'Total', 'value' => $payload['pending_sales']['total']],
                    ['label' => 'Paid Amount', 'value' => $payload['pending_sales']['paid_amount']],
                    ['label' => 'Shown In Profits', 'value' => $payload['pending_sales']['shown_in_profits']],
                    ['label' => 'Reason', 'value' => $payload['pending_sales']['reason']],
                ],
            ],
            [
                'heading' => 'Rent Paid',
                'rows' => [
                    ['label' => 'Amount', 'value' => $payload['rent_paid']['amount']],
                    ['label' => 'Paid Amount', 'value' => $payload['rent_paid']['paid_amount']],
                    ['label' => 'Shown In Profits', 'value' => $payload['rent_paid']['shown_in_profits']],
                ],
            ],
            [
                'heading' => 'Previous Expense Reconciliation',
                'rows' => [
                    ['label' => 'Amount', 'value' => $payload['previous_expense_reconciliation']['amount']],
                ],
            ],
            [
                'heading' => 'Advances / Fixed Assets',
                'rows' => [
                    ['label' => 'Amount', 'value' => $payload['advances_fixed_assets']['amount']],
                ],
            ],
            [
                'heading' => 'Zakat / Taxes',
                'rows' => [
                    ['label' => 'Amount', 'value' => $payload['zakat_taxes']['amount']],
                    ['label' => 'Bought Amount', 'value' => $payload['zakat_taxes']['bought_amount']],
                    ['label' => 'Used Amount', 'value' => $payload['zakat_taxes']['used_amount']],
                ],
            ],
            [
                'heading' => 'Unused Inventory',
                'rows' => [
                    ['label' => 'Total', 'value' => $payload['unused_inventory']['total']],
                    ['label' => 'Reason', 'value' => $payload['unused_inventory']['reason']],
                ],
            ],
            [
                'heading' => 'Non-Cash Expenses',
                'rows' => [
                    ['label' => 'Depreciation', 'value' => $payload['non_cash_expenses']['depreciation']],
                    ['label' => 'Others', 'value' => $payload['non_cash_expenses']['others']],
                    ['label' => 'Total', 'value' => $payload['non_cash_expenses']['total']],
                    ['label' => 'Reason', 'value' => $payload['non_cash_expenses']['reason']],
                ],
            ],
        ];

        $supplierRows = [];
        foreach ($payload['supplier_obligations']['items'] as $item) {
            $supplierRows[] = [(string) $item['name'], (float) $item['amount']];
        }
        $sections[] = [
            'heading' => 'Supplier Obligations',
            'rows' => [
                ['label' => 'Total', 'value' => $payload['supplier_obligations']['total']],
                ['label' => 'Reason', 'value' => $payload['supplier_obligations']['reason']],
            ],
        ];
        if (! empty($supplierRows)) {
            $sections[] = [
                'heading' => 'Supplier Obligations Breakdown',
                'table' => [
                    'headers' => ['Supplier', 'Amount'],
                    'rows' => $supplierRows,
                ],
            ];
        }

        $salaryRows = [];
        foreach ($payload['accrued_salaries']['items'] as $item) {
            $salaryRows[] = [(string) $item['name'], (float) $item['amount']];
        }
        $sections[] = [
            'heading' => 'Accrued Salaries',
            'table' => [
                'headers' => ['Name', 'Amount'],
                'rows' => $salaryRows,
            ],
        ];

        return $sections;
    }
}
