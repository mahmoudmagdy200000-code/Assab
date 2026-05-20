<?php

namespace Modules\Purchase\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
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

        // 3. Create Items (central items table)
        $this->command->info('📦 Creating Items...');
        $items = $this->createItems();

        // 4. Create Branch Items (pivot table)
        $this->command->info('🔗 Creating Branch Items (pivot)...');
        $branchItems = $this->createBranchItems($branches, $items);

        // 5. Create Branch Inventory
        $this->command->info('📊 Creating Branch Inventory...');
        $this->createBranchInventory($branches, $items);

        // 6. Create Suppliers (if not exists)
        $this->command->info('🏪 Creating Suppliers...');
        $suppliers = $this->createSuppliers();

        // 7. Create Supplier Items
        $this->command->info('🛒 Creating Supplier Items...');
        $this->createSupplierItems($suppliers, $items);

        // 8. Create Purchase Orders (last 3 months)
        $this->command->info('📋 Creating Purchase Orders...');
        $orders = $this->createPurchaseOrders($branches, $managers, $suppliers, $items);

        // 9. Create Price History
        $this->command->info('📈 Creating Price History...');
        $this->createPriceHistory($items, $suppliers);

        // 9. Force a direct supplier recommendation scenario (Rice 5kg)
        $this->command->info('🌟 Creating direct supplier recommendation scenario...');
        $this->call(DirectSupplierRecommendationSeeder::class);

        $this->command->info('✅ Purchase Test Data Seeded Successfully!');
        $this->command->info('📝 Summary:');
        $this->command->info('   - Branches: '.count($branches));
        $this->command->info('   - Branch Managers: '.count($managers));
        $this->command->info('   - Items: '.count($items));
        $this->command->info('   - Branch Items: '.count($branchItems));
        $this->command->info('   - Suppliers: '.count($suppliers));
        $this->command->info('   - Purchase Orders: '.count($orders));
    }

    /**
     * Create branches with different coordinates for distance calculation
     */
    private function createBranches(): array
    {
        $branches = [];

        // Main Branch
        $branches[] = Branch::updateOrCreate(
            ['name' => 'Main Branch'],
            [
                'location' => '123 Main St, City, Country',
                'image' => 'branches/main_branch.jpg',
                'opening_hours' => '08:00 - 22:00',
                'map_coordinates' => '24.7136,46.6753', // Riyadh
            ]
        );

        // Main Branch 2 (for testing getTransferItems)
        $branches[] = Branch::updateOrCreate(
            ['name' => 'Main Branch 2'],
            [
                'location' => '123 Main St, City, Country',
                'image' => 'branches/main_branch2.jpg',
                'opening_hours' => '08:00 - 22:00',
                'map_coordinates' => '24.8000,46.9000', // Different coordinates for distance calculation
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
            $email = 'manager'.($index + 1).'@assab.com';

            // Find unique phone number
            $phone = '+966'.($basePhone + $index);
            $phoneExists = BranchManager::where('phone', $phone)->exists();
            $phoneCounter = 0;
            while ($phoneExists && $phoneCounter < 100) {
                $phone = '+966'.($basePhone + $index + $phoneCounter + 1000);
                $phoneExists = BranchManager::where('phone', $phone)->exists();
                $phoneCounter++;
            }

            // Check if manager exists by email
            $manager = BranchManager::where('email', $email)->first();

            if ($manager) {
                // Update existing manager (only if phone is different)
                if ($manager->phone !== $phone && ! BranchManager::where('phone', $phone)->exists()) {
                    $manager->update([
                        'phone' => $phone,
                    ]);
                }
                $manager->update([
                    'name' => $managerNames[$index] ?? 'Manager '.($index + 1),
                    'branch_id' => $branch->id,
                    'status' => 'active',
                    'is_active' => true,
                ]);
            } else {
                // Create new manager
                $manager = BranchManager::create([
                    'name' => $managerNames[$index] ?? 'Manager '.($index + 1),
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
     * Create items (central items table)
     */
    private function createItems(): array
    {
        $itemsData = [
            ['name' => 'Cheese Burger', 'code' => 'PRD-004', 'unit' => 'piece', 'category' => 'Food', 'subcategory' => 'Fast Food'],
            ['name' => 'Chocolate Cake', 'code' => 'PRD-003', 'unit' => 'piece', 'category' => 'Desserts', 'subcategory' => 'Cake'],
            ['name' => 'Coffee Beans', 'code' => 'PRD-001', 'unit' => 'kg', 'category' => 'Beverages', 'subcategory' => 'Coffee'],
            ['name' => 'Green Tea', 'code' => 'PRD-002', 'unit' => 'box', 'category' => 'Beverages', 'subcategory' => 'Tea'],
            ['name' => 'Coca Cola 330ml', 'code' => 'ITEM-COCA-1', 'unit' => 'piece', 'category' => 'Beverages', 'subcategory' => 'Soft Drinks'],
            ['name' => 'Fresh Beef (Premium Grade)', 'code' => 'ITEM-FRESH-2', 'unit' => 'kg', 'category' => 'Meat', 'subcategory' => 'Beef'],
            ['name' => 'Chicken Breast (Frozen)', 'code' => 'ITEM-CHICK-3', 'unit' => 'kg', 'category' => 'Poultry', 'subcategory' => 'Chicken'],
            ['name' => 'Tomatoes (Fresh)', 'code' => 'ITEM-TOMAT-4', 'unit' => 'kg', 'category' => 'Vegetables', 'subcategory' => 'Fresh'],
            ['name' => 'Milk 1L', 'code' => 'ITEM-MILK-5', 'unit' => 'liter', 'category' => 'Dairy', 'subcategory' => 'Milk'],
            ['name' => 'Bread (White)', 'code' => 'ITEM-BREAD-6', 'unit' => 'piece', 'category' => 'Bakery', 'subcategory' => 'Bread'],
            ['name' => 'Rice 5kg', 'code' => 'ITEM-RICE-7', 'unit' => 'kg', 'category' => 'Grains', 'subcategory' => 'Rice'],
            ['name' => 'Olive Oil 1L', 'code' => 'ITEM-OIL-8', 'unit' => 'liter', 'category' => 'Oils', 'subcategory' => 'Olive Oil'],
        ];

        $items = [];
        foreach ($itemsData as $itemData) {
            $item = Item::updateOrCreate(
                ['code' => $itemData['code']],
                [
                    'name' => $itemData['name'],
                    'unit' => $itemData['unit'],
                    'category' => $itemData['category'],
                    'subcategory' => $itemData['subcategory'],
                    'is_active' => true,
                ]
            );
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Create branch items (pivot table - many-to-many relationship)
     */
    private function createBranchItems(array $branches, array $items): array
    {
        $branchItems = [];
        $prices = [38, 45, 27, 22, 2.5, 45.0, 25.0, 8.0, 6.5, 2.0, 35.0, 45.0];
        $quantities = [55, 70, 90, 110, 100, 150, 120, 80, 130, 95, 200, 75];

        foreach ($branches as $branch) {
            foreach ($items as $index => $item) {
                $branchItem = BranchItem::updateOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'item_id' => $item->id,
                    ],
                    [
                        'price' => $prices[$index] ?? 10.0,
                        'quantity' => $quantities[$index] ?? rand(50, 200),
                    ]
                );
                $branchItems[] = $branchItem;
            }
        }

        return $branchItems;
    }

    /**
     * Create branch inventory (item_id now references Item.id, not BranchItem.id)
     */
    private function createBranchInventory(array $branches, array $items): void
    {
        // Create inventory for Main Branch 2 (fromBranchId) with Cheese Burger
        $mainBranch2 = collect($branches)->firstWhere('name', 'Main Branch 2');
        $mainBranch = collect($branches)->firstWhere('name', 'Main Branch');

        if ($mainBranch2 && $mainBranch) {
            // Find Cheese Burger item
            $cheeseBurger = collect($items)->firstWhere('code', 'PRD-004');
            $chocolateCake = collect($items)->firstWhere('code', 'PRD-003');
            $coffeeBeans = collect($items)->firstWhere('code', 'PRD-001');
            $greenTea = collect($items)->firstWhere('code', 'PRD-002');

            // Create inventory for Main Branch 2 (fromBranch) - items with stock
            if ($cheeseBurger) {
                BranchInventory::updateOrCreate(
                    [
                        'branch_id' => $mainBranch2->id,
                        'item_id' => $cheeseBurger->id, // Item.id (new structure)
                    ],
                    [
                        'available_quantity' => 50,
                        'reserved_quantity' => 0,
                        'daily_consumption' => 10,
                        'weekend_forecast' => 15,
                        'quality' => QualityLevel::ECONOMY,
                        'earliest_expiry_date' => now()->addDays(7),
                        'cooling_status' => false,
                        'last_inventory_update' => now()->subHours(6),
                    ]
                );
            }

            if ($chocolateCake) {
                BranchInventory::updateOrCreate(
                    [
                        'branch_id' => $mainBranch2->id,
                        'item_id' => $chocolateCake->id,
                    ],
                    [
                        'available_quantity' => 70,
                        'reserved_quantity' => 0,
                        'daily_consumption' => 5,
                        'weekend_forecast' => 10,
                        'quality' => QualityLevel::STANDARD,
                        'earliest_expiry_date' => now()->addDays(14),
                        'cooling_status' => true,
                        'last_inventory_update' => now()->subHours(3),
                    ]
                );
            }

            if ($coffeeBeans) {
                BranchInventory::updateOrCreate(
                    [
                        'branch_id' => $mainBranch2->id,
                        'item_id' => $coffeeBeans->id,
                    ],
                    [
                        'available_quantity' => 90,
                        'reserved_quantity' => 0,
                        'daily_consumption' => 8,
                        'weekend_forecast' => 12,
                        'quality' => QualityLevel::PREMIUM,
                        'earliest_expiry_date' => now()->addDays(180),
                        'cooling_status' => false,
                        'last_inventory_update' => now()->subHours(12),
                    ]
                );
            }

            if ($greenTea) {
                BranchInventory::updateOrCreate(
                    [
                        'branch_id' => $mainBranch2->id,
                        'item_id' => $greenTea->id,
                    ],
                    [
                        'available_quantity' => 110,
                        'reserved_quantity' => 0,
                        'daily_consumption' => 6,
                        'weekend_forecast' => 9,
                        'quality' => QualityLevel::ECONOMY,
                        'earliest_expiry_date' => now()->addDays(365),
                        'cooling_status' => false,
                        'last_inventory_update' => now()->subHours(1),
                    ]
                );
            }
        }

        // Create inventory for all branches and items
        foreach ($branches as $branch) {
            foreach ($items as $index => $item) {
                // Skip if already created for Main Branch 2
                if ($branch->name === 'Main Branch 2' && in_array($item->code, ['PRD-004', 'PRD-003', 'PRD-001', 'PRD-002'])) {
                    continue;
                }

                BranchInventory::updateOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'item_id' => $item->id, // Item.id (new structure)
                    ],
                    [
                        'available_quantity' => rand(100, 300),
                        'reserved_quantity' => rand(0, 50),
                        'daily_consumption' => rand(5, 20),
                        'weekend_forecast' => rand(10, 30),
                        'quality' => QualityLevel::cases()[array_rand(QualityLevel::cases())],
                        'earliest_expiry_date' => now()->addDays(rand(7, 90)),
                        'cooling_status' => in_array($item->name, ['Fresh Beef (Premium Grade)', 'Chicken Breast (Frozen)', 'Milk 1L']),
                        'last_inventory_update' => now()->subHours(rand(1, 24)),
                    ]
                );
            }
        }
    }

    /**
     * Create suppliers
     */
    private function createSuppliers(): array
    {
        $suppliers = PurchaseSupplier::all();

        if ($suppliers->isEmpty()) {
            $seeder = new PurchaseSupplierSeeder;
            $seeder->setCommand($this->command);
            $seeder->run();
            $suppliers = PurchaseSupplier::all();
        }

        return $suppliers->toArray();
    }

    /**
     * Create supplier items (item_id now references Item.id)
     */
    private function createSupplierItems(array $suppliers, array $items): void
    {
        foreach ($suppliers as $supplier) {
            $supplierModel = PurchaseSupplier::find($supplier['id']);
            if (! $supplierModel) {
                continue;
            }

            foreach ($items as $item) {
                // Get price from BranchItem if exists, otherwise use default
                $branchItem = BranchItem::where('item_id', $item->id)->first();
                $basePrice = $branchItem ? $branchItem->price : 10.0;

                SupplierItem::updateOrCreate(
                    [
                        'supplier_id' => $supplierModel->id,
                        'item_id' => $item->id, // Item.id (new structure)
                    ],
                    [
                        'unit_price' => $basePrice * (1 + (rand(-10, 20) / 100)), // ±10-20% variation
                        'economy_price' => $basePrice * 0.85,
                        'standard_price' => $basePrice,
                        'premium_price' => $basePrice * 1.25,
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
     * Updated to work with Item model (new structure)
     */
    private function createPurchaseOrders(array $branches, array $managers, array $suppliers, array $items): array
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
                $maxItems = min($itemsCount, count($items));

                if ($maxItems > 0) {
                    $selectedItems = collect($items)->random($maxItems);

                    foreach ($selectedItems as $item) {
                        // Get BranchItem for this branch to get price
                        $branchItem = BranchItem::where('branch_id', $branch->id)
                            ->where('item_id', $item->id)
                            ->first();

                        $quantity = rand(10, 50);
                        $basePrice = $branchItem ? $branchItem->price : 10.0;
                        $unitPrice = $basePrice * (1 + (rand(-5, 15) / 100));
                        $totalPrice = ($quantity * $unitPrice) - (rand(0, 50)); // With discount

                        PurchaseOrderItem::create([
                            'purchase_order_id' => $order->id,
                            'item_id' => $item->id, // Item.id (new structure)
                            'item_name' => $item->name,
                            'item_sku' => $item->code,
                            'category' => $item->category,
                            'quantity_ordered' => $quantity,
                            'quantity_confirmed' => $status !== OrderStatus::PENDING ? $quantity : null,
                            'unit_of_measurement' => $item->unit ?? 'kg',
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
    private function createPriceHistory(array $items, array $suppliers): void
    {
        $orderTypes = [OrderType::DIRECT_SUPPLIER, OrderType::VIA_PURCHASING_OFFICER];

        foreach ($items as $item) {
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

                        // Get price from BranchItem if exists, otherwise use default
                        $branchItem = BranchItem::where('item_id', $item->id)->first();
                        $basePrice = $branchItem ? $branchItem->price : 10.0;

                        PriceHistory::create([
                            'item_id' => $item->id, // Item.id (new structure)
                            'item_name' => $item->name,
                            'source_type' => $orderType,
                            'source_id' => $sourceId,
                            'source_name' => $sourceName,
                            'unit_price' => $basePrice * (1 + (rand(-10, 20) / 100)),
                            'quality_level' => QualityLevel::cases()[array_rand(QualityLevel::cases())],
                            'unit_of_measurement' => $item->unit ?? 'kg',
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
