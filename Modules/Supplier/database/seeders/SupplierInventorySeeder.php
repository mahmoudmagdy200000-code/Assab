<?php

namespace Modules\Supplier\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierInventory;
use Modules\Supplier\Models\SupplierProduct;

class SupplierInventorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('📦 Seeding Supplier Inventory...');

        // Get all suppliers
        $suppliers = Supplier::where('is_active', true)->get();

        if ($suppliers->isEmpty()) {
            $this->command->warn('⚠️  No active suppliers found. Please seed suppliers first.');

            return;
        }

        $createdCount = 0;
        $updatedCount = 0;

        foreach ($suppliers as $supplier) {
            // Get all products for this supplier
            $products = SupplierProduct::where('supplier_id', $supplier->id)->get();

            if ($products->isEmpty()) {
                $this->command->warn("⚠️  No products found for supplier '{$supplier->name}'. Skipping...");

                continue;
            }

            foreach ($products as $product) {
                // Generate realistic inventory data
                $quantity = rand(100, 5000);
                $reservedQuantity = rand(0, (int) ($quantity * 0.3)); // 0-30% reserved
                $reorderLevel = (int) ($quantity * 0.2); // 20% of quantity
                $maxStockLevel = (int) ($quantity * 1.5); // 150% of quantity

                $inventory = SupplierInventory::updateOrCreate(
                    [
                        'supplier_id' => $supplier->id,
                        'product_id' => $product->id,
                    ],
                    [
                        'quantity' => $quantity,
                        'reserved_quantity' => $reservedQuantity,
                        'reorder_level' => $reorderLevel,
                        'max_stock_level' => $maxStockLevel,
                        'last_restocked_at' => now()->subDays(rand(1, 30)),
                        'expiry_date' => rand(0, 5) > 2 ? now()->addDays(rand(30, 365)) : null, // 60% have expiry
                        'batch_number' => 'BATCH-'.strtoupper(uniqid()),
                        'notes' => rand(0, 3) > 2 ? 'Fresh stock received' : null,
                    ]
                );

                if ($inventory->wasRecentlyCreated) {
                    $createdCount++;
                } else {
                    $updatedCount++;
                }
            }

            $this->command->info("✅ Supplier '{$supplier->name}': ".$products->count().' inventory records');
        }

        $this->command->info('🎉 Supplier Inventory Seeding Complete!');
        $this->command->info("   Created: {$createdCount} records");
        $this->command->info("   Updated: {$updatedCount} records");
    }
}
