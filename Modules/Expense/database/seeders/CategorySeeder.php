<?php

namespace Modules\Expense\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Expense\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        // Purchase Categories
        $purchaseCategories = [
            'Office Supplies' => ['Stationery', 'Printer Supplies', 'Filing & Storage'],
            'Electronics' => ['Computers', 'Mobile Devices', 'Accessories'],
            'Furniture' => ['Desks', 'Chairs', 'Storage Units'],
            'Cleaning Supplies' => ['Detergents', 'Tools', 'Equipment'],
            'Pantry Items' => ['Beverages', 'Snacks', 'Condiments'],
        ];

        foreach ($purchaseCategories as $parentName => $children) {
            $parent = Category::create([
                'name' => $parentName,
                'type' => 'purchase',
                'is_active' => true,
            ]);

            foreach ($children as $childName) {
                Category::create([
                    'name' => $childName,
                    'parent_id' => $parent->id,
                    'type' => 'purchase',
                    'is_active' => true,
                ]);
            }
        }

        // Expense Categories
        $expenseCategories = [
            'Utilities' => ['Electricity', 'Water', 'Internet', 'Phone'],
            'Maintenance' => ['Building Repairs', 'Equipment Repairs', 'Plumbing', 'Electrical'],
            'Transportation' => ['Fuel', 'Vehicle Maintenance', 'Parking', 'Tolls'],
            'Marketing' => ['Advertising', 'Promotions', 'Events', 'Digital Marketing'],
            'Professional Services' => ['Legal', 'Accounting', 'Consulting', 'IT Services'],
            'Insurance' => ['Property Insurance', 'Liability Insurance', 'Vehicle Insurance'],
        ];

        foreach ($expenseCategories as $parentName => $children) {
            $parent = Category::create([
                'name' => $parentName,
                'type' => 'expense',
                'is_active' => true,
            ]);

            foreach ($children as $childName) {
                Category::create([
                    'name' => $childName,
                    'parent_id' => $parent->id,
                    'type' => 'expense',
                    'is_active' => true,
                ]);
            }
        }

        // Add some inactive categories for testing
        Category::create([
            'name' => 'Deprecated Category',
            'type' => 'purchase',
            'is_active' => false,
        ]);
    }
}
