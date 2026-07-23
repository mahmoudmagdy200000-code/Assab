<?php

namespace Modules\BrandOwner\Services\Financial;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\BrandOwner\Models\BrandOwnerPriceScenario;
use Modules\Purchase\Models\BranchItem;

/**
 * Price simulator (Menu Engineering → Pricing Simulator).
 *
 * Lets a brand owner model a price change on a single branch item and see the
 * resulting unit / monthly profit swing, then persist the scenario for later
 * review, export or email. All figures flow through {@see FinancialDataService}
 * so production cost is derived identically everywhere.
 */
class PriceSimulatorService
{
    /** Default expected monthly growth shown on the baseline info card. */
    private const DEFAULT_GROWTH_PERCENTAGE = 5.0;

    public function __construct(
        private readonly FinancialDataService $data,
        private readonly FinancialReportExporter $exporter,
    ) {}

    /**
     * Distinct active catalog items that have at least one branch_item row.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function items(): array
    {
        return $this->data->branchItems(null)
            ->filter(fn (BranchItem $bi) => $bi->item !== null)
            ->unique('item_id')
            ->take(200)
            ->map(fn (BranchItem $bi) => [
                'id' => (string) $bi->item_id,
                'name' => (string) $bi->item->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Baseline pricing card for a branch item, or null when the item is not
     * configured for that branch.
     *
     * @return array{
     *   item_id: string, item_name: string, current_selling_price: float,
     *   production_cost: float, current_monthly_sales: int,
     *   expected_growth_percentage: float
     * }|null
     */
    public function itemInfo(string $branchId, string $itemId): ?array
    {
        $branchItem = $this->branchItem($branchId, $itemId);

        if ($branchItem === null) {
            return null;
        }

        $price = (float) $branchItem->price;

        return [
            'item_id' => (string) $itemId,
            'item_name' => (string) ($branchItem->item?->name ?? ''),
            'current_selling_price' => $price,
            'production_cost' => $this->data->productionCost($price),
            'current_monthly_sales' => (int) round((float) $branchItem->quantity),
            'expected_growth_percentage' => self::DEFAULT_GROWTH_PERCENTAGE,
        ];
    }

    /**
     * Run a price-change simulation, persist it, and return the result payload,
     * or null when the branch item does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function simulate(
        Model $owner,
        string $branchId,
        string $itemId,
        float $changePrice,
        float $expectedGrowthDecline,
    ): ?array {
        $baseline = $this->itemInfo($branchId, $itemId);

        if ($baseline === null) {
            return null;
        }

        $currentSales = $baseline['current_monthly_sales'];
        $productionCost = $baseline['production_cost'];
        $currentSellingPrice = $baseline['current_selling_price'];

        $newPrice = round($changePrice, 2);
        $expectedSales = (int) round($currentSales * (1 + $expectedGrowthDecline / 100));
        $expectedSalesChangePercentage = round($expectedGrowthDecline, 2);

        $newUnitProfit = round($newPrice - $productionCost, 2);
        $currentUnitProfit = $currentSellingPrice - $productionCost;
        $newUnitProfitChangePercentage = $this->data->percentageChange($newUnitProfit, $currentUnitProfit);

        $newMonthlyProfit = round($newUnitProfit * $expectedSales, 2);
        $currentMonthlyProfit = $currentUnitProfit * $currentSales;
        $profitChange = round($newMonthlyProfit - $currentMonthlyProfit, 2);
        $profitChangePercentage = $this->data->percentageChange($newMonthlyProfit, $currentMonthlyProfit);

        $scenario = DB::transaction(fn () => BrandOwnerPriceScenario::create([
            'brand_owner_id' => $owner->getKey(),
            'branch_id' => $branchId,
            'branch_name' => (string) ($this->data->branchName($branchId) ?? ''),
            'item_id' => $itemId,
            'item_name' => $baseline['item_name'],
            'current_selling_price' => $currentSellingPrice,
            'production_cost' => $productionCost,
            'current_monthly_sales' => $currentSales,
            'expected_growth_percentage' => round($expectedGrowthDecline, 2),
            'new_price' => $newPrice,
            'expected_sales' => $expectedSales,
            'expected_sales_change_percentage' => $expectedSalesChangePercentage,
            'new_unit_profit' => $newUnitProfit,
            'new_unit_profit_change_percentage' => $newUnitProfitChangePercentage,
            'new_monthly_profit' => $newMonthlyProfit,
            'profit_change' => $profitChange,
            'profit_change_percentage' => $profitChangePercentage,
        ]));

        return $this->toResult($scenario);
    }

    /**
     * The owner's saved scenarios, latest first (capped at 50).
     *
     * @return array<int, array<string, mixed>>
     */
    public function savedScenarios(Model $owner): array
    {
        return BrandOwnerPriceScenario::query()
            ->where('brand_owner_id', $owner->getKey())
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (BrandOwnerPriceScenario $row) => $this->toResult($row))
            ->all();
    }

    /**
     * Export a saved scenario to a file, or null when it is not the owner's.
     *
     * @return array{file_url: string}|null
     */
    public function export(Model $owner, string $scenarioId, string $formatType): ?array
    {
        $scenario = $this->findOwned($owner, $scenarioId);

        if ($scenario === null) {
            return null;
        }

        return $this->exporter->export(
            $owner,
            'price_simulator',
            $this->reportTitle($scenario),
            $this->sections($scenario),
            $formatType,
            ['scenario_id' => $scenarioId, 'format_type' => $formatType],
        );
    }

    /**
     * Email a saved scenario. Returns false when it is not the owner's.
     */
    public function email(Model $owner, string $scenarioId, string $email): bool
    {
        $scenario = $this->findOwned($owner, $scenarioId);

        if ($scenario === null) {
            return false;
        }

        $this->exporter->email(
            'price_simulator',
            $this->reportTitle($scenario),
            $this->sections($scenario),
            $email,
        );

        return true;
    }

    // ----------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------

    private function branchItem(string $branchId, string $itemId): ?BranchItem
    {
        return BranchItem::query()
            ->with('item:id,name,is_active')
            ->where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->first();
    }

    private function findOwned(Model $owner, string $scenarioId): ?BrandOwnerPriceScenario
    {
        return BrandOwnerPriceScenario::query()
            ->where('brand_owner_id', $owner->getKey())
            ->whereKey($scenarioId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function toResult(BrandOwnerPriceScenario $row): array
    {
        return [
            'new_price' => (float) $row->new_price,
            'expected_sales' => (int) $row->expected_sales,
            'expected_sales_change_percentage' => (float) $row->expected_sales_change_percentage,
            'new_unit_profit' => (float) $row->new_unit_profit,
            'new_unit_profit_change_percentage' => (float) $row->new_unit_profit_change_percentage,
            'new_monthly_profit' => (float) $row->new_monthly_profit,
            'profit_change' => (float) $row->profit_change,
            'profit_change_percentage' => (float) $row->profit_change_percentage,
            'date' => $row->created_at->toIso8601String(),
            'branch_name' => (string) $row->branch_name,
            'item_name' => (string) $row->item_name,
            'current_selling_price' => (float) $row->current_selling_price,
            'production_cost' => (float) $row->production_cost,
            'current_monthly_sales' => (int) $row->current_monthly_sales,
            'expected_growth_percentage' => (float) $row->expected_growth_percentage,
        ];
    }

    private function reportTitle(BrandOwnerPriceScenario $row): string
    {
        return 'Price Simulator Scenario - '.(string) $row->item_name;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sections(BrandOwnerPriceScenario $row): array
    {
        $result = $this->toResult($row);

        return [
            [
                'heading' => 'Scenario',
                'rows' => [
                    ['label' => 'Branch', 'value' => $result['branch_name']],
                    ['label' => 'Item', 'value' => $result['item_name']],
                    ['label' => 'Date', 'value' => $result['date']],
                ],
            ],
            [
                'heading' => 'Baseline',
                'rows' => [
                    ['label' => 'Current Selling Price', 'value' => $result['current_selling_price']],
                    ['label' => 'Production Cost', 'value' => $result['production_cost']],
                    ['label' => 'Current Monthly Sales', 'value' => $result['current_monthly_sales']],
                    ['label' => 'Expected Growth %', 'value' => $result['expected_growth_percentage']],
                ],
            ],
            [
                'heading' => 'Simulation Outcome',
                'rows' => [
                    ['label' => 'New Price', 'value' => $result['new_price']],
                    ['label' => 'Expected Sales', 'value' => $result['expected_sales']],
                    ['label' => 'Expected Sales Change %', 'value' => $result['expected_sales_change_percentage']],
                    ['label' => 'New Unit Profit', 'value' => $result['new_unit_profit']],
                    ['label' => 'New Unit Profit Change %', 'value' => $result['new_unit_profit_change_percentage']],
                    ['label' => 'New Monthly Profit', 'value' => $result['new_monthly_profit']],
                    ['label' => 'Profit Change', 'value' => $result['profit_change']],
                    ['label' => 'Profit Change %', 'value' => $result['profit_change_percentage']],
                ],
            ],
        ];
    }
}
