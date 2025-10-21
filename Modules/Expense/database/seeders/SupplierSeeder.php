<?php

namespace Modules\Expense\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Expense\Database\Factories\SupplierFactory;
use Modules\Expense\Models\Supplier;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            [
                'name' => 'Office Depot Egypt',
                'phone' => '+20 2 1234 5678',
                'email' => 'sales@officedepot-eg.com',
                'tax_id' => '123-456-7890',
                'address' => 'Cairo, Egypt',
                'is_active' => true,
            ],
            [
                'name' => 'Tech Solutions Co.',
                'phone' => '+20 2 2345 6789',
                'email' => 'info@techsolutions.com',
                'tax_id' => '234-567-8901',
                'address' => 'Giza, Egypt',
                'is_active' => true,
            ],
            [
                'name' => 'Clean Pro Services',
                'phone' => '+20 2 3456 7890',
                'email' => 'contact@cleanpro.com',
                'tax_id' => '345-678-9012',
                'address' => 'Alexandria, Egypt',
                'is_active' => true,
            ],
            [
                'name' => 'Furniture World',
                'phone' => '+20 2 4567 8901',
                'email' => 'orders@furnitureworld.com',
                'tax_id' => '456-789-0123',
                'address' => 'Cairo, Egypt',
                'is_active' => true,
            ],
            [
                'name' => 'Energy Plus',
                'phone' => '+20 2 5678 9012',
                'email' => 'billing@energyplus.com',
                'tax_id' => '567-890-1234',
                'address' => 'Cairo, Egypt',
                'is_active' => true,
            ],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::create($supplier);
        }

        // Create additional random suppliers
        Supplier::factory()->count(15)->create();

        // Create some inactive suppliers
        Supplier::factory()->count(3)->inactive()->create();
    }
}
