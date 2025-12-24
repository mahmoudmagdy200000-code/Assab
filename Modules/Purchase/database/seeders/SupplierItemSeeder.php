<?php

namespace Modules\Purchase\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\SupplierItem;
use Modules\Supplier\Models\Supplier;

class SupplierItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🛒 Seeding Supplier Items...');

        // Get all suppliers
        $suppliers = Supplier::where('is_active', true)->get();
        
        if ($suppliers->isEmpty()) {
            $this->command->warn('⚠️  No active suppliers found. Please seed suppliers first.');
            return;
        }

        // Get all items
        $items = Item::where('is_active', true)->get();
        
        if ($items->isEmpty()) {
            $this->command->warn('⚠️  No active items found. Please seed items first.');
            return;
        }

        $createdCount = 0;
        $updatedCount = 0;

        foreach ($suppliers as $supplier) {
            // Each supplier will have 60-80% of available items
            $itemsForSupplier = $items->random(rand((int)($items->count() * 0.6), (int)($items->count() * 0.8)));

            foreach ($itemsForSupplier as $item) {
                // Get price from BranchItem if exists, otherwise use default
                $branchItem = BranchItem::where('item_id', $item->id)->first();
                $basePrice = $branchItem ? (float) $branchItem->price : rand(10, 100);

                // Calculate prices with variation
                $priceVariation = 1 + (rand(-15, 25) / 100); // ±15-25% variation
                $unitPrice = round($basePrice * $priceVariation, 2);

                $supplierItem = SupplierItem::updateOrCreate(
                    [
                        'supplier_id' => $supplier->id,
                        'item_id' => $item->id,
                    ],
                    [
                        'unit_price' => $unitPrice,
                        'economy_price' => round($unitPrice * 0.85, 2),
                        'standard_price' => round($unitPrice * 1.0, 2),
                        'premium_price' => round($unitPrice * 1.25, 2),
                        'is_available' => rand(0, 10) > 1, // 90% available
                        'min_order_quantity' => rand(5, 50),
                        'max_order_quantity' => rand(500, 2000),
                        'delivery_hours' => rand(12, 72),
                        'rating' => round(rand(35, 50) / 10, 1), // 3.5 to 5.0
                    ]
                );

                if ($supplierItem->wasRecentlyCreated) {
                    $createdCount++;
                } else {
                    $updatedCount++;
                }
            }

            $this->command->info("✅ Supplier '{$supplier->name}': " . $itemsForSupplier->count() . " items");
        }

        $this->command->info("🎉 Supplier Items Seeding Complete!");
        $this->command->info("   Created: {$createdCount} items");
        $this->command->info("   Updated: {$updatedCount} items");
    }
}

