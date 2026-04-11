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

    private const MAX_ITEMS = 4;

    private const DEFAULT_PRICE = 0;

    private const DEFAULT_QUANTITY = 0;

    /**
     * 1) Seed branch_item for the branch so /inventory/daily-quick/branch-items returns data.
     * 2) Seed daily inventory schedule and add those items as daily products.
     */
    public function run(): void
    {
        $branchId = self::BRANCH_ID;

        // Remove all existing branch items and keep only MAX_ITEMS
        BranchItem::query()->where('branch_id', $branchId)->delete();

        $activeItems = Item::query()
            ->where('is_active', true)
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

        $this->command?->info("Branch items reset: {$activeItems->count()} items.");

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
            ->limit(self::MAX_ITEMS)
            ->pluck('item_id')
            ->unique()
            ->values()
            ->all();

        // Reset schedule items and add only MAX_ITEMS
        DailyInventoryScheduleItem::query()
            ->where('daily_inventory_schedule_id', $schedule->id)
            ->delete();

        $sortOrder = 0;
        foreach ($itemIds as $itemId) {
            DailyInventoryScheduleItem::create([
                'daily_inventory_schedule_id' => $schedule->id,
                'item_id' => $itemId,
                'sort_order' => $sortOrder++,
            ]);
        }

        $this->command?->info("Set " . count($itemIds) . " daily product(s) in branch schedule.");

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
