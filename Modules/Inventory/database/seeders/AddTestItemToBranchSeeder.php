<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;

/**
 * Adds a fresh test item to the branch so it appears in /inventory/daily-quick/branch-items.
 * The item is not linked to any active session, so the API will return it immediately.
 */
class AddTestItemToBranchSeeder extends Seeder
{
    private const BRANCH_ID = '019bd5e8-4837-700b-81b5-c9f2080fcbff';

    public function run(): void
    {
        $branchId = self::BRANCH_ID;

        $item = Item::create([
            'name'        => 'Test Item - Daily Inventory',
            'code'        => 'TEST-DAILY-001',
            'unit'        => 'kg',
            'category'    => 'Test',
            'subcategory' => 'Daily Test',
            'description' => 'Test item for daily inventory session testing.',
            'is_active'   => true,
        ]);

        BranchItem::create([
            'branch_id' => $branchId,
            'item_id'   => $item->id,
            'price'     => 10.00,
            'quantity'  => 100,
        ]);

        $this->command?->info("Created test item [{$item->name}] (id: {$item->id}) and linked to branch {$branchId}.");
        $this->command?->info("Now call GET /api/v1/inventory/daily-quick/branch-items — the item will appear.");
    }
}
