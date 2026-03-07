<?php

namespace Modules\Purchase\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;

/**
 * Seeds branch_item for a specific branch so the Branch items API returns data.
 * Adds all active items to the branch with default price and quantity.
 */
class BranchItemsForBranchSeeder extends Seeder
{
    private const BRANCH_ID = '019bd5e8-4837-700b-81b5-c9f2080fcbff';

    /**
     * Default price/quantity when adding an item to the branch.
     */
    private const DEFAULT_PRICE = 0;

    private const DEFAULT_QUANTITY = 0;

    public function run(): void
    {
        $branchId = self::BRANCH_ID;

        $existingItemIds = BranchItem::query()
            ->where('branch_id', $branchId)
            ->pluck('item_id')
            ->all();

        $items = Item::query()
            ->where('is_active', true)
            ->whereNotIn('id', $existingItemIds)
            ->get(['id']);

        $added = 0;
        foreach ($items as $item) {
            BranchItem::create([
                'branch_id' => $branchId,
                'item_id' => $item->id,
                'price' => self::DEFAULT_PRICE,
                'quantity' => self::DEFAULT_QUANTITY,
            ]);
            $added++;
        }

        $total = count($existingItemIds) + $added;
        $this->command?->info("Added {$added} item(s) to branch. Total branch items: {$total}.");
    }
}
