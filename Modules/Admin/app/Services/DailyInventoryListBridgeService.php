<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Inventory\Models\DailyInventorySchedule;
use Modules\Inventory\Models\DailyInventoryScheduleItem;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item as PurchaseItem;

/**
 * Meeting 2026-08-04 «الأصناف مختلفة عن الموجود في الجرد»: the accountant's
 * «تحديد أصناف الجرد اليومي» wrote ONLY the dashboard-side list
 * (`asab_branch_inventory_list`). The app builds its count sheet from
 * `daily_inventory_schedule_items` → legacy `items`, so the two lists were
 * unrelated by construction: whatever the accountant ticked, the branch kept
 * counting whatever the mobile schedule already held.
 *
 * This bridges the selection into that schedule, matching the ASAB catalog row
 * to a legacy item by code, else by name (the same rule the raw-material upload
 * uses), creating the legacy row when it is genuinely new. It also seeds
 * `branch_item`, because the branch pickers read that pivot rather than `items`.
 */
class DailyInventoryListBridgeService
{
    public function __construct(private readonly \Psr\Log\LoggerInterface $log) {}

    /**
     * Mirror a branch's chosen catalog items onto its mobile daily-count list.
     *
     * @param  array<int, string>  $catalogItemIds
     * @return array{items:int, created:int}
     */
    public function sync(string $branchId, array $catalogItemIds): array
    {
        $catalog = InventoryCatalogItem::whereIn('id', $catalogItemIds)->get();
        if ($catalog->isEmpty()) {
            // An empty selection still clears the app's list — that is the
            // accountant deliberately emptying the sheet, not a no-op.
            $this->replaceScheduleItems($branchId, []);

            return ['items' => 0, 'created' => 0];
        }

        $created = 0;
        $itemIds = [];

        foreach ($catalog as $row) {
            $mobile = $this->resolveMobileItem($row, $created);
            $itemIds[] = $mobile->id;

            // The mobile item list for a branch is the branch_item pivot; an
            // item absent from it is invisible to the counting screen.
            BranchItem::firstOrCreate(
                ['branch_id' => $branchId, 'item_id' => $mobile->id],
                ['price' => 0, 'quantity' => 0],
            );
        }

        $this->replaceScheduleItems($branchId, array_values(array_unique($itemIds)));

        return ['items' => count(array_unique($itemIds)), 'created' => $created];
    }

    /**
     * Create-only resolution, mirroring the raw-material importer: reuse the
     * legacy row that carries this code (or name when codeless) rather than
     * minting a duplicate item the branch would see twice.
     */
    private function resolveMobileItem(InventoryCatalogItem $row, int &$created): PurchaseItem
    {
        $existing = $row->code
            ? PurchaseItem::withTrashed()->where('code', $row->code)->first()
            : PurchaseItem::withTrashed()->where('name', $row->name)->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        $created++;

        return PurchaseItem::create([
            'name' => $row->name,
            'code' => $row->code ?: null,
            'unit' => $row->unit ?: 'kg',
            'category' => $row->category,
            'is_active' => true,
        ]);
    }

    /**
     * The schedule is the app's count sheet. A branch with no schedule yet gets
     * one — otherwise the accountant's first save would have nowhere to land and
     * the branch would keep seeing an empty list. Times are the branch's own
     * once it edits them; the defaults only matter for the very first save.
     *
     * @param  array<int, string>  $itemIds
     */
    private function replaceScheduleItems(string $branchId, array $itemIds): void
    {
        DB::transaction(function () use ($branchId, $itemIds) {
            $schedule = DailyInventorySchedule::where('branch_id', $branchId)->first();

            if ($schedule === null) {
                if ($itemIds === []) {
                    return; // nothing to clear and nothing to schedule
                }

                $schedule = DailyInventorySchedule::create([
                    'branch_id' => $branchId,
                    'start_date' => now()->toDateString(),
                    'start_time' => '20:00',
                    'is_active' => true,
                ]);
            }

            DailyInventoryScheduleItem::where('daily_inventory_schedule_id', $schedule->id)->delete();

            foreach (array_values($itemIds) as $index => $itemId) {
                DailyInventoryScheduleItem::create([
                    'daily_inventory_schedule_id' => $schedule->id,
                    'item_id' => $itemId,
                    'sort_order' => $index,
                ]);
            }
        });
    }
}
