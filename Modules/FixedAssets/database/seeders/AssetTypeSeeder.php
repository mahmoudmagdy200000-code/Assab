<?php

namespace Modules\FixedAssets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\FixedAssets\Models\AssetType;

class AssetTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'Coffee Machines',
            'Refrigerators',
            'Furniture',
            'POS Devices',
            'Lighting',
            'Cooking Equipment',
            'Air Conditioners',
            'Storage Shelves',
        ];

        foreach ($types as $name) {
            AssetType::updateOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
