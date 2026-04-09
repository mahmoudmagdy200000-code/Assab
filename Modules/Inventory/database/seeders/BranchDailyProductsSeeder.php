<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\DailyInventorySchedule;
use Modules\Inventory\Models\DailyInventoryScheduleItem;
use Modules\Inventory\Models\MonthlyInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;

class BranchDailyProductsSeeder extends Seeder
{
    private const BRANCH_ID = '019bd5e8-4837-700b-81b5-c9f2080fcbff';

    private const MAX_ITEMS = 5;

    private const DEFAULT_PRICE = 0;

    private const DEFAULT_QUANTITY = 0;

    /**
     * 1) Seed branch_item for the branch so /inventory/daily-quick/branch-items returns data.
     * 2) Seed daily inventory schedule and add those items as daily products.
     */
    public function run(): void
    {
        $branchId = self::BRANCH_ID;

        $existingBranchItemIds = BranchItem::query()
            ->where('branch_id', $branchId)
            ->pluck('item_id')
            ->all();

        $activeItems = Item::query()
            ->where('is_active', true)
            ->whereNotIn('id', $existingBranchItemIds)
            ->limit(self::MAX_ITEMS)
            ->get(['id']);

        foreach ($activeItems as $item) {
            BranchItem::create([
                'branch_id' => $branchId,
                'item_id' => $item->id,
                'price' => self::DEFAULT_PRICE,
                'quantity' => self::DEFAULT_QUANTITY,
            ]);
        }

        $branchItemsCount = count($existingBranchItemIds) + $activeItems->count();
        $this->command?->info("Branch items: {$branchItemsCount} (added " . $activeItems->count() . "). /branch-items API will return these.");

        $schedule = DailyInventorySchedule::query()
            ->byBranch($branchId)
            ->first();

        if (! $schedule) {
            $schedule = DailyInventorySchedule::create([
                'branch_id' => $branchId,
                'start_date' => now()->startOfDay(),
                'start_time' => '08:00:00',
                'is_active' => true,
                'created_by' => null,
            ]);
            $this->command?->info("Created daily inventory schedule for branch {$branchId}.");
        } else {
            $this->command?->info("Using existing daily inventory schedule for branch {$branchId}.");
        }

        $itemIds = BranchItem::query()
            ->where('branch_id', $branchId)
            ->pluck('item_id')
            ->unique()
            ->values()
            ->all();

        $existingItemIds = DailyInventoryScheduleItem::query()
            ->where('daily_inventory_schedule_id', $schedule->id)
            ->pluck('item_id')
            ->all();

        $toAdd = array_diff($itemIds, $existingItemIds);
        $sortOrder = count($existingItemIds);

        foreach ($toAdd as $itemId) {
            DailyInventoryScheduleItem::create([
                'daily_inventory_schedule_id' => $schedule->id,
                'item_id' => $itemId,
                'sort_order' => $sortOrder++,
            ]);
        }

        $added = count($toAdd);
        $total = count($existingItemIds) + $added;
        $this->command?->info("Added {$added} daily product(s) to branch schedule. Total items in schedule: {$total}.");

        $this->resetMonthlyInventory($branchId);
    }

    private function resetMonthlyInventory(string $branchId): void
    {
        $deletedCount = MonthlyInventory::query()
            ->withTrashed()
            ->where('branch_id', $branchId)
            ->forceDelete();

        $this->command?->info("Removed {$deletedCount} monthly inventory record(s) for branch {$branchId}.");
    }
}
