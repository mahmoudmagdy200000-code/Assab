<?php

namespace Modules\Purchase\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\PriceHistory;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseSupplier;
use Modules\Purchase\Models\SupplierItem;

class PurchaseTestDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🌱 Starting Purchase Test Data Seeding...');

        // 1. Create/Update Branches with different coordinates
        $this->command->info('📍 Creating Branches...');
        $branches = $this->createBranches();

        // 2. Create Branch Managers
        $this->command->info('👤 Creating Branch Managers...');
        $managers = $this->createBranchManagers($branches);

        // 3. Create Branch Items
        $this->command->info('📦 Creating Branch Items...');
        $branchItems = $this->createBranchItems($branches);

        // 4. Create Branch Inventory
        $this->command->info('📊 Creating Branch Inventory...');
        $this->createBranchInventory($branchItems);

        // 5. Create Suppliers (if not exists)
        $this->command->info('🏪 Creating Suppliers...');
        $suppliers = $this->createSuppliers();

        // 6. Create Supplier Items
        $this->command->info('🛒 Creating Supplier Items...');
        $this->createSupplierItems($suppliers, $branchItems);

        // 7. Create Purchase Orders (last 3 months)
        $this->command->info('📋 Creating Purchase Orders...');
        $orders = $this->createPurchaseOrders($branches, $managers, $suppliers, $branchItems);

        // 8. Create Price History
        $this->command->info('📈 Creating Price History...');
        $this->createPriceHistory($branchItems, $suppliers);

        $this->command->info('✅ Purchase Test Data Seeded Successfully!');
        $this->command->info('📝 Summary:');
        $this->command->info('   - Branches: ' . count($branches));
        $this->command->info('   - Branch Managers: ' . count($managers));
        $this->command->info('   - Branch Items: ' . count($branchItems));
        $this->command->info('   - Suppliers: ' . count($suppliers));
        $this->command->info('   - Purchase Orders: ' . count($orders));
    }

    /**
     * Create branches with different coordinates for distance calculation
     */
    private function createBranches(): array
    {
        $branches = [];

        // Riyadh coordinates (main)
        $branches[] = Branch::updateOrCreate(
            ['name' => 'Main Branch'],
            [
                'location' => 'King Fahd Road, Riyadh, Saudi Arabia',
                'image' => 'branches/main_branch.jpg',
                'opening_hours' => '08:00 - 22:00',
                'map_coordinates' => '24.7136,46.6753', // Riyadh
            ]
        );

        // Jeddah (about 950 km from Riyadh)
        $branches[] = Branch::updateOrCreate(
            ['name' => 'Jeddah Branch'],
            [
                'location' => 'Corniche Road, Jeddah, Saudi Arabia',
                'image' => 'branches/jeddah_branch.jpg',
                'opening_hours' => '08:00 - 22:00',
                'map_coordinates' => '21.4858,39.1925', // Jeddah
            ]
        );

        // Dammam (about 400 km from Riyadh)
        $branches[] = Branch::updateOrCreate(
            ['name' => 'Dammam Branch'],
            [
                'location' => 'King Faisal Road, Dammam, Saudi Arabia',
                'image' => 'branches/dammam_branch.jpg',
                'opening_hours' => '08:00 - 22:00',
                'map_coordinates' => '26.4207,50.0888', // Dammam
            ]
        );

        // Khobar (close to Dammam)
        $branches[] = Branch::updateOrCreate(
            ['name' => 'Khobar Branch'],
            [
                'location' => 'Corniche, Al Khobar, Saudi Arabia',
                'image' => 'branches/khobar_branch.jpg',
                'opening_hours' => '08:00 - 22:00',
                'map_coordinates' => '26.2041,50.1970', // Khobar
            ]
        );

        return $branches;
    }

    /**
     * Create branch managers
     */
    private function createBranchManagers(array $branches): array
    {
        $managers = [];
        $managerNames = ['Ahmed Al-Saud', 'Mohammed Al-Rashid', 'Khalid Al-Mansouri', 'Fahad Al-Zahrani'];
        $basePhone = 5000000001; // Start from a unique number

        foreach ($branches as $index => $branch) {
            $email = 'manager' . ($index + 1) . '@assab.com';

            // Find unique phone number
            $phone = '+966' . ($basePhone + $index);
            $phoneExists = BranchManager::where('phone', $phone)->exists();
            $phoneCounter = 0;
            while ($phoneExists && $phoneCounter < 100) {
                $phone = '+966' . ($basePhone + $index + $phoneCounter + 1000);
                $phoneExists = BranchManager::where('phone', $phone)->exists();
                $phoneCounter++;
            }

            // Check if manager exists by email
            $manager = BranchManager::where('email', $email)->first();

            if ($manager) {
                // Update existing manager (only if phone is different)
                if ($manager->phone !== $phone && !BranchManager::where('phone', $phone)->exists()) {
                    $manager->update([
                        'phone' => $phone,
                    ]);
                }
                $manager->update([
                    'name' => $managerNames[$index] ?? 'Manager ' . ($index + 1),
                    'branch_id' => $branch->id,
                    'status' => 'active',
                    'is_active' => true,
                ]);
            } else {
                // Create new manager
                $manager = BranchManager::create([
                    'name' => $managerNames[$index] ?? 'Manager ' . ($index + 1),
                    'email' => $email,
                    'phone' => $phone,
                    'password' => bcrypt('password123'),
                    'branch_id' => $branch->id,
                    'status' => 'active',
                    'is_active' => true,
                    'is_first_login' => false,
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                ]);
            }

            // Note: branch_manager_id is not in branches table, relationship is through branch_id in branch_managers
            $managers[] = $manager;
        }

        return $managers;
    }

    /**
     * Create branch items
     */
    private function createBranchItems(array $branches): array
    {
        $items = [];
        $itemNames = [
            'Coca Cola 330ml',
            'Fresh Beef (Premium Grade)',
            'Chicken Breast (Frozen)',
            'Tomatoes (Fresh)',
            'Milk 1L',
            'Bread (White)',
            'Rice 5kg',
            'Olive Oil 1L',
        ];

        foreach ($branches as $branch) {
            foreach ($itemNames as $index => $itemName) {
                $item = BranchItem::updateOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'item_name' => $itemName,
                    ],
                    [
                        'item_code' => 'ITEM-' . strtoupper(substr(str_replace(' ', '', $itemName), 0, 6)) . '-' . ($index + 1),
                        'item_unit' => ['piece', 'kg', 'kg', 'kg', 'liter', 'piece', 'kg', 'liter'][$index] ?? 'kg',
                        'item_price' => [2.5, 45.0, 25.0, 8.0, 6.5, 2.0, 35.0, 45.0][$index] ?? 10.0,
                        'item_quantity' => rand(50, 200),
                        // Note: category and subcategory columns may not exist in database
                        // If migration 2025_12_09_000001_add_category_to_branch_item_table has been run, uncomment:
                        // 'category' => ['Beverages', 'Meat', 'Poultry', 'Vegetables', 'Dairy', 'Bakery', 'Grains', 'Oils'][$index] ?? 'General',
                        // 'subcategory' => null,
                    ]
                );
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Create branch inventory
     */
    private function createBranchInventory(array $branchItems): void
    {
        foreach ($branchItems as $item) {
            BranchInventory::updateOrCreate(
                [
                    'branch_id' => $item->branch_id,
                    'item_id' => $item->id,
                ],
                [
                    'available_quantity' => rand(100, 300),
                    'reserved_quantity' => rand(0, 50),
                    'daily_consumption' => rand(5, 20),
                    'weekend_forecast' => rand(10, 30),
                    'quality' => QualityLevel::cases()[array_rand(QualityLevel::cases())],
                    'earliest_expiry_date' => now()->addDays(rand(7, 90)),
                    'cooling_status' => in_array($item->item_name, ['Fresh Beef (Premium Grade)', 'Chicken Breast (Frozen)', 'Milk 1L']),
                    'last_inventory_update' => now()->subHours(rand(1, 24)),
                ]
            );
        }
    }

    /**
     * Create suppliers
     */
    private function createSuppliers(): array
    {
        $suppliers = PurchaseSupplier::all();

        if ($suppliers->isEmpty()) {
            $seeder = new PurchaseSupplierSeeder();
            $seeder->setCommand($this->command);
            $seeder->run();
            $suppliers = PurchaseSupplier::all();
        }

        return $suppliers->toArray();
    }

    /**
     * Create supplier items
     */
    private function createSupplierItems(array $suppliers, array $branchItems): void
    {
        $uniqueItems = collect($branchItems)->unique('item_name');

        foreach ($suppliers as $supplier) {
            $supplierModel = PurchaseSupplier::find($supplier['id']);
            if (!$supplierModel) {
                continue;
            }

            foreach ($uniqueItems as $item) {
                SupplierItem::updateOrCreate(
                    [
                        'supplier_id' => $supplierModel->id,
                        'item_id' => $item->id,
                    ],
                    [
                        'unit_price' => $item->item_price * (1 + (rand(-10, 20) / 100)), // ±10-20% variation
                        'economy_price' => $item->item_price * 0.85,
                        'standard_price' => $item->item_price,
                        'premium_price' => $item->item_price * 1.25,
                        'min_order_quantity' => rand(10, 50),
                        'max_order_quantity' => rand(500, 1000),
                        'delivery_hours' => rand(12, 72),
                        'is_available' => true,
                        'rating' => round(rand(35, 50) / 10, 1), // 3.5 to 5.0
                    ]
                );
            }
        }
    }

    /**
     * Create purchase orders (last 3 months)
     */
    private function createPurchaseOrders(array $branches, array $managers, array $suppliers, array $branchItems): array
    {
        $orders = [];
        $orderTypes = [OrderType::DIRECT_SUPPLIER, OrderType::VIA_PURCHASING_OFFICER, OrderType::INTERNAL_TRANSFER];
        $statuses = [OrderStatus::CONFIRMED, OrderStatus::CLOSED, OrderStatus::DELIVERED];

        // Create orders for last 3 months
        for ($month = 0; $month < 3; $month++) {
            $monthDate = now()->subMonths($month);

            // Create 5-10 orders per month
            $ordersPerMonth = rand(5, 10);

            for ($i = 0; $i < $ordersPerMonth; $i++) {
                $orderType = $orderTypes[array_rand($orderTypes)];
                $branch = $branches[array_rand($branches)];
                $manager = $managers[array_rand($managers)];
                $status = $statuses[array_rand($statuses)];

                $orderData = [
                    'order_type' => $orderType,
                    'status' => $status,
                    'branch_id' => $branch->id,
                    'requested_by' => $manager->id,
                    'priority' => rand(0, 1) ? 'high' : 'normal',
                    'quality_level' => QualityLevel::cases()[array_rand(QualityLevel::cases())],
                    'tax_rate' => 15.00,
                    'created_at' => $monthDate->copy()->subDays(rand(0, 28))->subHours(rand(0, 23)),
                ];

                // Set sourceable based on order type
                if ($orderType === OrderType::DIRECT_SUPPLIER) {
                    $supplier = $suppliers[array_rand($suppliers)];
                    $orderData['supplier_id'] = $supplier['id'];
                    $orderData['sourceable_type'] = PurchaseSupplier::class;
                    $orderData['sourceable_id'] = $supplier['id'];
                } elseif ($orderType === OrderType::VIA_PURCHASING_OFFICER) {
                    $orderData['sourceable_type'] = BranchManager::class;
                    $orderData['sourceable_id'] = $manager->id;
                } elseif ($orderType === OrderType::INTERNAL_TRANSFER) {
                    $fromBranch = $branches[array_rand($branches)];
                    while ($fromBranch->id === $branch->id) {
                        $fromBranch = $branches[array_rand($branches)];
                    }
                    $orderData['from_branch_id'] = $fromBranch->id;
                    $orderData['to_branch_id'] = $branch->id;
                    $orderData['sourceable_type'] = Branch::class;
                    $orderData['sourceable_id'] = $fromBranch->id;
                }

                // Set timestamps based on status
                $createdAt = $orderData['created_at'];
                $orderData['submitted_at'] = $createdAt->copy()->addHours(rand(1, 6));
                $orderData['confirmed_at'] = $orderData['submitted_at']->copy()->addHours(rand(1, 24));
                $orderData['updated_at'] = $orderData['confirmed_at'];

                $order = PurchaseOrder::create($orderData);

                // Create order items (1-3 items per order)
                $itemsCount = rand(1, 3);
                $branchItemsForBranch = collect($branchItems)->where('branch_id', $branch->id);
                $maxItems = min($itemsCount, $branchItemsForBranch->count());

                if ($maxItems > 0) {
                    $selectedItems = $branchItemsForBranch->random($maxItems);

                    foreach ($selectedItems as $item) {
                        $quantity = rand(10, 50);
                        $unitPrice = $item->item_price * (1 + (rand(-5, 15) / 100));
                        $totalPrice = ($quantity * $unitPrice) - (rand(0, 50)); // With discount

                        PurchaseOrderItem::create([
                            'purchase_order_id' => $order->id,
                            'item_id' => $item->id,
                            'item_name' => $item->item_name,
                            'item_sku' => $item->item_code,
                            'category' => $item->category,
                            'quantity_ordered' => $quantity,
                            'quantity_confirmed' => $status !== OrderStatus::PENDING ? $quantity : null,
                            'unit_of_measurement' => $item->item_unit ?? 'kg',
                            'unit_price' => $unitPrice,
                            'total_price' => $totalPrice,
                            'discount' => rand(0, 50),
                            'quality_ordered' => QualityLevel::cases()[array_rand(QualityLevel::cases())],
                        ]);
                    }

                    // Calculate totals
                    $order->calculateTotals();
                    $orders[] = $order;
                }
            }
        }

        return $orders;
    }

    /**
     * Create price history
     */
    private function createPriceHistory(array $branchItems, array $suppliers): void
    {
        $uniqueItems = collect($branchItems)->unique('item_name');
        $orderTypes = [OrderType::DIRECT_SUPPLIER, OrderType::VIA_PURCHASING_OFFICER];

        foreach ($uniqueItems as $item) {
            foreach ($orderTypes as $orderType) {
                // Create price history for last 3 months
                for ($month = 0; $month < 3; $month++) {
                    $monthDate = now()->subMonths($month);
                    $periodMonth = $monthDate->format('Y-m');

                    // Create 2-5 price records per month
                    $recordsPerMonth = rand(2, 5);

                    for ($i = 0; $i < $recordsPerMonth; $i++) {
                        $sourceId = null;
                        $sourceName = null;

                        if ($orderType === OrderType::DIRECT_SUPPLIER) {
                            $supplier = $suppliers[array_rand($suppliers)];
                            $sourceId = $supplier['id'];
                            $sourceName = $supplier['name'];
                        } else {
                            $sourceName = 'Purchasing Officer';
                        }

                        PriceHistory::create([
                            'item_id' => $item->id,
                            'item_name' => $item->item_name,
                            'source_type' => $orderType,
                            'source_id' => $sourceId,
                            'source_name' => $sourceName,
                            'unit_price' => $item->item_price * (1 + (rand(-10, 20) / 100)),
                            'quality_level' => QualityLevel::cases()[array_rand(QualityLevel::cases())],
                            'unit_of_measurement' => $item->item_unit ?? 'kg',
                            'delivery_days' => rand(1, 5),
                            'rating' => round(rand(35, 50) / 10, 1),
                            'recorded_date' => $monthDate->copy()->subDays(rand(0, 28)),
                            'period_month' => $periodMonth,
                        ]);
                    }
                }
            }
        }
    }
}
