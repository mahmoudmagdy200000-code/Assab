<?php

namespace Modules\FixedAssets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\FixedAssets\Models\AssetZone;

class AssetZoneSeeder extends Seeder
{
    public function run(): void
    {
        $zones = ['Front of House', 'Kitchen', 'Storage', 'Office', 'Outdoor'];

        Branch::query()->each(function (Branch $branch) use ($zones) {
            foreach ($zones as $name) {
                AssetZone::updateOrCreate(
                    ['branch_id' => $branch->id, 'name' => $name],
                    ['is_active' => true]
                );
            }
        });
    }
}
