<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Admin\Models\AsabBrandPackage;

class AsabBrandPackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            ['code' => 'silver', 'name' => 'فضي', 'name_en' => 'Silver', 'price' => 100000],
            ['code' => 'gold', 'name' => 'ذهبي', 'name_en' => 'Gold', 'price' => 175000],
            ['code' => 'platinum', 'name' => 'بلاتيني', 'name_en' => 'Platinum', 'price' => 250000],
        ];

        foreach ($packages as $p) {
            // withTrashed: never trip the unique code index on a soft-deleted row.
            AsabBrandPackage::withTrashed()->updateOrCreate(
                ['code' => $p['code']],
                $p + ['is_active' => true],
            );
        }
    }
}
