<?php

namespace Modules\Supplier\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Purchase\Models\Item;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierProduct;

class SupplierProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('📦 Seeding Supplier Products...');

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
            // Each supplier will have 50-70% of available items as products
            $itemsForSupplier = $items->random(rand((int) ($items->count() * 0.5), (int) ($items->count() * 0.7)));

            foreach ($itemsForSupplier as $item) {
                // Calculate prices with variation
                $basePrice = rand(10, 200);
                $priceVariation = 1 + (rand(-20, 30) / 100); // ±20-30% variation
                $unitPrice = round($basePrice * $priceVariation, 2);

                $product = SupplierProduct::updateOrCreate(
                    [
                        'supplier_id' => $supplier->id,
                        'item_id' => $item->id,
                    ],
                    [
                        'name' => $item->name,
                        'description' => "High quality {$item->name} from {$supplier->name}",
                        'image' => $item->logo,
                        'sku' => 'SKU-'.strtoupper(substr($item->code, 0, 8)).'-'.strtoupper(substr($supplier->id, 0, 8)),
                        'unit_price' => $unitPrice,
                        'economy_price' => round($unitPrice * 0.85, 2),
                        'standard_price' => round($unitPrice * 1.0, 2),
                        'premium_price' => round($unitPrice * 1.25, 2),
                        'is_available' => rand(0, 10) > 1, // 90% available
                        'min_order_quantity' => rand(5, 50),
                        'max_order_quantity' => rand(500, 2000),
                        'stock_quantity' => rand(100, 5000),
                        'delivery_hours' => rand(12, 72),
                        'quality_level' => ['economy', 'standard', 'premium'][rand(0, 2)],
                        'rating' => round(rand(35, 50) / 10, 1), // 3.5 to 5.0
                        'specifications' => [
                            'weight' => rand(100, 5000).'g',
                            'dimensions' => rand(10, 50).'x'.rand(10, 50).'x'.rand(5, 30).'cm',
                        ],
                        'images' => $item->logo ? [$item->logo] : null,
                        'categories' => [$item->category, $item->subcategory],
                    ]
                );

                if ($product->wasRecentlyCreated) {
                    $createdCount++;
                } else {
                    $updatedCount++;
                }
            }

            $this->command->info("✅ Supplier '{$supplier->name}': ".$itemsForSupplier->count().' products');
        }

        $this->command->info('🎉 Supplier Products Seeding Complete!');
        $this->command->info("   Created: {$createdCount} products");
        $this->command->info("   Updated: {$updatedCount} products");
    }
}
