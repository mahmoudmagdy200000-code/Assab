<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\DailyInventorySchedule;
use Modules\Inventory\Models\DailyInventoryScheduleItem;
use Modules\Purchase\Models\BranchItem;

class TrimBranchItemsToFiveSeeder extends Seeder
{
    private const BRANCH_ID = '019bd5e8-4837-700b-81b5-c9f2080fcbff';

    private const KEEP_COUNT = 5;

    /**
     * Keep only 5 branch items for the branch; delete the rest.
     * Also trims daily inventory schedule to only those 5 items.
     */
    public function run(): void
    {
        $branchId = self::BRANCH_ID;

        $all = BranchItem::query()
            ->where('branch_id', $branchId)
            ->orderBy('created_at')
            ->get();

        if ($all->count() <= self::KEEP_COUNT) {
            $this->command?->info("Branch already has {$all->count()} items (≤ ".self::KEEP_COUNT.'). Nothing to trim.');

            return;
        }

        $toKeep = $all->take(self::KEEP_COUNT);
        $toDelete = $all->slice(self::KEEP_COUNT);
        $keepItemIds = $toKeep->pluck('item_id')->all();

        foreach ($toDelete as $branchItem) {
            $branchItem->delete();
        }

        $this->command?->info('Deleted '.$toDelete->count().' branch item(s). Kept '.self::KEEP_COUNT.'.');

        $schedule = DailyInventorySchedule::query()
            ->byBranch($branchId)
            ->first();

        if ($schedule) {
            $removed = DailyInventoryScheduleItem::query()
                ->where('daily_inventory_schedule_id', $schedule->id)
                ->whereNotIn('item_id', $keepItemIds)
                ->delete();
            $this->command?->info("Removed {$removed} item(s) from daily inventory schedule.");
        }
    }
}
