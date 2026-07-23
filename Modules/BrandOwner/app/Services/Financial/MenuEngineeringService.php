<?php

namespace Modules\BrandOwner\Services\Financial;

use Illuminate\Support\Collection;

/**
 * Menu-engineering (BCG-style quadrant) report.
 *
 * NOTE: this legacy mobile backend has no per-item POS sales table, so true
 * "popularity" (units sold) and "profitability" (realised item margin) are not
 * tracked. As documented on FinancialDataService, we proxy popularity with
 * branch_item.quantity and profitability with the unit margin
 * (price - derived production cost). Items are classified against the MEDIAN of
 * each proxy into the four menu-engineering quadrants.
 */
class MenuEngineeringService
{
    public function __construct(
        private readonly FinancialDataService $data,
        private readonly FinancialReportExporter $exporter,
    ) {}

    /**
     * Build the full menu-engineering payload for a branch + period pair.
     *
     * @return array<string, mixed>
     */
    public function build(
        ?int $year,
        ?int $month,
        ?int $comparedYear,
        ?int $comparedMonth,
        ?string $branchId,
    ): array {
        $period = $this->data->resolvePeriod($year, $month);

        $compared = ($comparedYear !== null && $comparedMonth !== null)
            ? $this->data->resolvePeriod($comparedYear, $comparedMonth)
            : $this->data->previousMonth($period['year'], $period['month']);

        // branch_id is optional: fall back to the first branch (DB default).
        $branch = $this->data->resolveBranchRef($branchId);
        $branchId = $branch['id'];

        $items = $this->classify($branchId);

        $total = $items->count();
        $totalAmount = (float) $items->sum('amount');
        $totalMargin = (float) $items->sum(fn (array $i) => $i['unit_margin'] * $i['qty']);

        $stars = $items->filter(fn (array $i) => $i['high_pop'] && $i['high_profit']);
        $puzzles = $items->filter(fn (array $i) => $i['high_pop'] && ! $i['high_profit']);
        $workhorses = $items->filter(fn (array $i) => ! $i['high_pop'] && $i['high_profit']);
        $dogs = $items->filter(fn (array $i) => ! $i['high_pop'] && ! $i['high_profit']);

        return [
            'year' => $period['year'],
            'month' => $period['month'],
            'month_name' => $period['month_name'],
            'compared_year' => $compared['year'],
            'compared_month' => $compared['month'],
            'compared_month_name' => $compared['month_name'],
            'branch_id' => $branch['id'],
            'branch_name' => $branch['name'],

            // is_high_profitability follows the doc formula: (q is stars || workhorses).
            'puzzles' => $this->overview($puzzles, $total, false),
            'stars' => $this->overview($stars, $total, true),
            'dogs' => $this->overview($dogs, $total, false),
            'workhorses' => $this->overview($workhorses, $total, true),

            'puzzles_category' => $this->categoryBlock($puzzles, $total, $totalAmount, $totalMargin),
            'stars_category' => $this->categoryBlock($stars, $total, $totalAmount, $totalMargin),
            'dogs_category' => $this->categoryBlock($dogs, $total, $totalAmount, $totalMargin),
            'workhorses_category' => $this->categoryBlock($workhorses, $total, $totalAmount, $totalMargin),
        ];
    }

    /**
     * Load branch items and tag each with its quadrant flags against the medians.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function classify(?string $branchId): Collection
    {
        $branchItems = $this->data->branchItems($branchId);

        // Real production cost per item (latest purchase price), fetched in one
        // query to avoid an N+1; falls back to the ratio estimate per item.
        $costMap = $this->data->latestPurchaseUnitCosts($branchItems->pluck('item_id')->all());

        $items = $branchItems->map(function ($bi) use ($costMap) {
            $price = (float) $bi->price;
            $cost = $this->data->resolveItemCost($price, $costMap[$bi->item_id] ?? null);
            $unitMargin = $price - $cost;
            $qty = (float) $bi->quantity;

            return [
                'price' => $price,
                'cost' => $cost,
                'unit_margin' => $unitMargin,
                'qty' => $qty,
                'amount' => $price * $qty,
                'category' => (string) ($bi->item->category ?? 'Uncategorized'),
            ];
        })->values();

        $medMargin = $this->median($items->pluck('unit_margin')->all());
        $medQty = $this->median($items->pluck('qty')->all());

        return $items->map(function (array $i) use ($medMargin, $medQty) {
            $i['high_profit'] = $i['unit_margin'] >= $medMargin;
            $i['high_pop'] = $i['qty'] >= $medQty;

            return $i;
        });
    }

    /**
     * Quadrant headline block.
     *
     * @param  Collection<int, array<string, mixed>>  $quadrant
     * @return array<string, mixed>
     */
    private function overview(Collection $quadrant, int $total, bool $isHighProfitability): array
    {
        $count = $quadrant->count();

        return [
            'items_count' => $count,
            'percentage' => $this->data->ratio((float) $count, (float) $total),
            'is_high_profitability' => $isHighProfitability,
        ];
    }

    /**
     * Quadrant category breakdown block.
     *
     * @param  Collection<int, array<string, mixed>>  $quadrant
     * @return array<string, mixed>
     */
    private function categoryBlock(
        Collection $quadrant,
        int $total,
        float $totalAmount,
        float $totalMargin,
    ): array {
        $count = $quadrant->count();
        $amount = (float) $quadrant->sum('amount');

        $categories = $quadrant
            ->groupBy('category')
            ->map(function (Collection $group, $name) use ($totalAmount, $totalMargin) {
                $catAmount = (float) $group->sum('amount');
                $catMargin = (float) $group->sum(fn (array $i) => $i['unit_margin'] * $i['qty']);

                return [
                    'category' => (string) $name,
                    'sales_percentage' => $this->data->ratio($catAmount, $totalAmount),
                    'profit_percentage' => $this->data->ratio($catMargin, $totalMargin),
                ];
            })
            ->values()
            ->all();

        return [
            'items_count' => $count,
            'percentage' => $this->data->ratio((float) $count, (float) $total),
            'amount' => $amount,
            'amount_percentage' => $this->data->ratio($amount, $totalAmount),
            'items' => $categories,
        ];
    }

    /**
     * Median of a numeric list (0.0 when empty).
     *
     * @param  array<int, float>  $values
     */
    private function median(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        sort($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 0
            ? (($values[$mid - 1] + $values[$mid]) / 2)
            : (float) $values[$mid];
    }

    // ----------------------------------------------------------------
    // Export / email
    // ----------------------------------------------------------------

    /**
     * Export the report to a file, returning {file_url}.
     *
     * @return array{file_url: string}
     */
    public function export(
        \Illuminate\Database\Eloquent\Model $owner,
        array $input,
    ): array {
        $payload = $this->build(
            $input['year'],
            $input['month'],
            $input['compared_year'],
            $input['compared_month'],
            $input['branch_id'] ?? null,
        );

        return $this->exporter->export(
            $owner,
            'menu_engineering',
            $this->title($payload),
            $this->sections($payload),
            $input['format_type'],
            $input,
        );
    }

    /**
     * Email the report to the given address.
     */
    public function email(array $input): void
    {
        $payload = $this->build(
            $input['year'],
            $input['month'],
            $input['compared_year'],
            $input['compared_month'],
            $input['branch_id'] ?? null,
        );

        $this->exporter->email(
            'menu_engineering',
            $this->title($payload),
            $this->sections($payload),
            $input['email'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function title(array $payload): string
    {
        return sprintf(
            'Menu Engineering - %s %d - %s',
            $payload['month_name'],
            $payload['year'],
            (string) ($payload['branch_name'] ?? 'All Branches'),
        );
    }

    /**
     * Build presentation sections from the same computed payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function sections(array $payload): array
    {
        $quadrantRows = [];
        foreach (['stars', 'puzzles', 'workhorses', 'dogs'] as $key) {
            $q = $payload[$key];
            $quadrantRows[] = [
                'label' => ucfirst($key),
                'value' => sprintf('%d items (%s%%)', $q['items_count'], $q['percentage']),
            ];
        }

        $sections = [[
            'heading' => 'Quadrant Overview',
            'rows' => $quadrantRows,
        ]];

        foreach (['stars', 'puzzles', 'workhorses', 'dogs'] as $key) {
            $block = $payload[$key.'_category'];
            $rows = [];
            foreach ($block['items'] as $cat) {
                $rows[] = [
                    $cat['category'],
                    $cat['sales_percentage'].'%',
                    $cat['profit_percentage'].'%',
                ];
            }

            $sections[] = [
                'heading' => sprintf(
                    '%s - Categories (%d items, %s SAR)',
                    ucfirst($key),
                    $block['items_count'],
                    $block['amount'],
                ),
                'table' => [
                    'headers' => ['Category', 'Sales %', 'Profit %'],
                    'rows' => $rows,
                ],
            ];
        }

        return $sections;
    }
}
