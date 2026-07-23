<?php

namespace Modules\BrandOwner\Services\Financial;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\BrandOwner\Models\BrandOwnerItemTest;

/**
 * Item profitability test (Menu Engineering → Item Test).
 *
 * Computes the unit economics of a prospective menu item, classifies it on the
 * menu-engineering matrix, persists the test for the owner, and builds the
 * export/email payload — all from the shared FinancialDataService calc core.
 */
class ItemTestService
{
    public function __construct(
        private readonly FinancialDataService $data,
        private readonly FinancialReportExporter $exporter,
    ) {}

    /**
     * Run the test, persist it for the owner, and return the result payload.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function submit(Model $owner, array $input): array
    {
        $price = (float) $input['expected_selling_price'];
        $cost = (float) $input['production_cost'];
        $expectedSales = (int) $input['expected_sales'];
        $expectedGrowth = (float) $input['expected_growth'];

        $unitProfit = $price - $cost;
        $profitMargin = $this->data->ratio($unitProfit, $price);
        $expectedMonthlyProfit = round($unitProfit * $expectedSales, 2);

        $classification = $this->classify($profitMargin);
        $menuImpact = $this->menuImpact($classification);
        $branchName = (string) ($this->data->branchName($input['branch_id']) ?? '');

        $row = DB::transaction(fn () => BrandOwnerItemTest::create([
            'brand_owner_id' => $owner->getKey(),
            'branch_id' => $input['branch_id'],
            'branch_name' => $branchName,
            'item_name' => $input['item_name'],
            'expected_selling_price' => $price,
            'production_cost' => $cost,
            'expected_sales' => $expectedSales,
            'expected_growth' => $expectedGrowth,
            'profit_margin' => $profitMargin,
            'expected_monthly_profit' => $expectedMonthlyProfit,
            'expected_classification' => $classification,
            'menu_impact' => $menuImpact,
        ]));

        return $this->present($row);
    }

    /**
     * The owner's saved tests, latest first, capped at 50.
     *
     * @return array<int, array<string, mixed>>
     */
    public function savedTests(Model $owner): array
    {
        return BrandOwnerItemTest::query()
            ->where('brand_owner_id', $owner->getKey())
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (BrandOwnerItemTest $row) => $this->present($row))
            ->all();
    }

    /**
     * Export a saved test to a file and return {file_url}, or null if not found.
     *
     * @return array{file_url: string}|null
     */
    public function export(Model $owner, string $testId, string $formatType): ?array
    {
        $row = $this->find($owner, $testId);

        if ($row === null) {
            return null;
        }

        return $this->exporter->export(
            $owner,
            'item_test',
            $this->title($row),
            $this->sections($row),
            $formatType,
            ['test_id' => $testId, 'format_type' => $formatType],
        );
    }

    /**
     * Email a saved test to $email. Returns false if the test was not found.
     */
    public function email(Model $owner, string $testId, string $email): bool
    {
        $row = $this->find($owner, $testId);

        if ($row === null) {
            return false;
        }

        $this->exporter->email(
            'item_test',
            $this->title($row),
            $this->sections($row),
            $email,
        );

        return true;
    }

    // ----------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------

    private function find(Model $owner, string $testId): ?BrandOwnerItemTest
    {
        return BrandOwnerItemTest::query()
            ->where('brand_owner_id', $owner->getKey())
            ->whereKey($testId)
            ->first();
    }

    private function classify(float $profitMargin): string
    {
        return match (true) {
            $profitMargin >= 50.0 => 'Star',
            $profitMargin >= 35.0 => 'Workhorse',
            $profitMargin >= 20.0 => 'Puzzle',
            default => 'Dog',
        };
    }

    private function menuImpact(string $classification): string
    {
        return match ($classification) {
            'Star', 'Workhorse' => 'High profitability item recommended for addition',
            'Puzzle' => 'Moderate profitability, review pricing',
            default => 'Low profitability, not recommended',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BrandOwnerItemTest $row): array
    {
        $profitMargin = (float) $row->profit_margin;

        return [
            'id' => $row->id,
            'profit_margin' => $profitMargin,
            'profit_margin_label' => number_format($profitMargin, 2).'%',
            'expected_monthly_profit' => (float) $row->expected_monthly_profit,
            'expected_classification' => (string) $row->expected_classification,
            'menu_impact' => (string) $row->menu_impact,
            'date' => $row->created_at->clone()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'branch_name' => (string) $row->branch_name,
            'item_name' => (string) $row->item_name,
            'expected_selling_price' => (float) $row->expected_selling_price,
            'production_cost' => (float) $row->production_cost,
            'expected_sales' => (int) $row->expected_sales,
            'expected_growth' => (float) $row->expected_growth,
        ];
    }

    private function title(BrandOwnerItemTest $row): string
    {
        return 'Item Test — '.$row->item_name;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sections(BrandOwnerItemTest $row): array
    {
        return [
            [
                'heading' => 'Item Test',
                'rows' => [
                    ['label' => 'Branch', 'value' => (string) $row->branch_name],
                    ['label' => 'Item', 'value' => (string) $row->item_name],
                    ['label' => 'Date', 'value' => $row->created_at->clone()->utc()->format('Y-m-d\TH:i:s.v\Z')],
                ],
            ],
            [
                'heading' => 'Inputs',
                'rows' => [
                    ['label' => 'Expected Selling Price', 'value' => (float) $row->expected_selling_price],
                    ['label' => 'Production Cost', 'value' => (float) $row->production_cost],
                    ['label' => 'Expected Sales', 'value' => (int) $row->expected_sales],
                    ['label' => 'Expected Growth', 'value' => (float) $row->expected_growth],
                ],
            ],
            [
                'heading' => 'Results',
                'rows' => [
                    ['label' => 'Profit Margin', 'value' => number_format((float) $row->profit_margin, 2).'%'],
                    ['label' => 'Expected Monthly Profit', 'value' => (float) $row->expected_monthly_profit],
                    ['label' => 'Classification', 'value' => (string) $row->expected_classification],
                    ['label' => 'Menu Impact', 'value' => (string) $row->menu_impact],
                ],
            ],
        ];
    }
}
