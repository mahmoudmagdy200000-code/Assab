<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;

/**
 * Adds test items to the branch so they appear in /inventory/daily-quick/branch-items.
 * Uses firstOrCreate so the seeder can be run multiple times without duplicate key errors.
 */
class AddTestItemToBranchSeeder extends Seeder
{
    private const BRANCH_ID = '019bd5e8-4837-700b-81b5-c9f2080fcbff';

    private const SUBCATEGORY = 'Daily Test';

    /** @var array<int, array{name: string, code: string, unit: string, category: string, subcategory: string, description: string, price: float, quantity: int}> */
    private const TEST_ITEMS = [
        [
            'name'        => 'Test Item - Daily Inventory 1',
            'code'        => 'TEST-DAILY-001',
            'unit'        => 'kg',
            'category'    => 'Test',
            'subcategory' => self::SUBCATEGORY,
            'description' => 'Test item for daily inventory session testing.',
            'price'       => 10.00,
            'quantity'    => 100,
        ],
        [
            'name'        => 'Test Item - Daily Inventory 2',
            'code'        => 'TEST-DAILY-002',
            'unit'        => 'piece',
            'category'    => 'Test',
            'subcategory' => self::SUBCATEGORY,
            'description' => 'Second test item for daily inventory.',
            'price'       => 5.00,
            'quantity'    => 50,
        ],
        [
            'name'        => 'Test Item - Daily Inventory 3',
            'code'        => 'TEST-DAILY-003',
            'unit'        => 'box',
            'category'    => 'Test',
            'subcategory' => self::SUBCATEGORY,
            'description' => 'Third test item for daily inventory.',
            'price'       => 15.00,
            'quantity'    => 30,
        ],
        [
            'name'        => 'Test Item - Daily Inventory 4',
            'code'        => 'TEST-DAILY-004',
            'unit'        => 'liter',
            'category'    => 'Test',
            'subcategory' => self::SUBCATEGORY,
            'description' => 'Fourth test item for daily inventory.',
            'price'       => 8.00,
            'quantity'    => 80,
        ],
        [
            'name'        => 'Test Item - Daily Inventory 5',
            'code'        => 'TEST-DAILY-005',
            'unit'        => 'pack',
            'category'    => 'Test',
            'subcategory' => self::SUBCATEGORY,
            'description' => 'Fifth test item for daily inventory.',
            'price'       => 12.00,
            'quantity'    => 40,
        ],
    ];

    public function run(): void
    {
        $branchId = self::BRANCH_ID;

        foreach (self::TEST_ITEMS as $data) {
            $item = Item::firstOrCreate(
                ['code' => $data['code']],
                [
                    'name'        => $data['name'],
                    'unit'        => $data['unit'],
                    'category'    => $data['category'],
                    'subcategory' => $data['subcategory'],
                    'description' => $data['description'],
                    'is_active'   => true,
                ]
            );

            BranchItem::firstOrCreate(
                [
                    'branch_id' => $branchId,
                    'item_id'   => $item->id,
                ],
                [
                    'price'    => $data['price'],
                    'quantity' => $data['quantity'],
                ]
            );

            $this->command?->info("Test item [{$item->name}] (code: {$item->code}, id: {$item->id}) linked to branch {$branchId}.");
        }

        $this->command?->info('Done. Call GET /api/v1/inventory/daily-quick/branch-items — the items will appear.');
    }
}
