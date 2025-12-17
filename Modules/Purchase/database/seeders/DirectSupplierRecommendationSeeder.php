<?php

namespace Modules\Purchase\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\PriceHistory;
use Modules\Purchase\Models\PurchaseSupplier;
use Modules\Purchase\Models\SupplierItem;

class DirectSupplierRecommendationSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::first();
        if (!$branch) {
            $this->command?->warn('⚠️ No branches found; skipping direct supplier recommendation seed.');
            return;
        }

        $branchItem = BranchItem::updateOrCreate(
            ['branch_id' => $branch->id, 'item_name' => 'Rice 5kg'],
            [
                'item_code' => 'ITEM-RICE5K-7',
                'item_unit' => 'kg',
                'item_price' => 35,
                'item_quantity' => 120,
            ]
        );

        $supplier = PurchaseSupplier::updateOrCreate(
            ['email' => 'recommendations@freshfoods.com'],
            [
                'name' => 'Fresh Foods Priority',
                'phone' => '+966501230000',
                'address' => 'Riyadh, Saudi Arabia',
                'tax_id' => '310123450000111',
                'status' => 'online',
                'is_active' => true,
                'contact_methods' => ['email', 'whatsapp', 'app'],
                'default_delivery_hours' => 12,
                'min_order_amount' => 100.00,
                'average_response_time_hours' => 1.00,
                'response_rate_percentage' => 98.00,
                'rating' => 4.90,
                'total_orders' => 50,
                'completed_orders' => 49,
                'categories' => ['grains'],
                'last_seen_at' => now(),
            ]
        );

        $pricing = [
            'unit_price' => 29.90,
            'economy_price' => 29.00,
            'standard_price' => 29.90,
            'premium_price' => 32.00,
            'min_order_quantity' => 1,
            'max_order_quantity' => 1000,
            'delivery_hours' => 12,
            'rating' => 4.90,
            'is_available' => true,
        ];

        SupplierItem::updateOrCreate(
            ['supplier_id' => $supplier->id, 'item_id' => $branchItem->id],
            $pricing
        );

        PriceHistory::updateOrCreate(
            [
                'item_id' => $branchItem->id,
                'source_type' => OrderType::DIRECT_SUPPLIER,
                'source_id' => $supplier->id,
                'period_month' => now()->format('Y-m'),
            ],
            [
                'item_name' => $branchItem->item_name,
                'source_name' => $supplier->name,
                'unit_price' => $pricing['unit_price'],
                'quality_level' => QualityLevel::STANDARD,
                'unit_of_measurement' => $branchItem->item_unit ?? 'kg',
                'delivery_days' => 1,
                'rating' => $pricing['rating'],
                'recorded_date' => now()->subDays(2),
            ]
        );

        $this->command?->info('✅ Direct supplier recommendation scenario seeded.');
    }
}

