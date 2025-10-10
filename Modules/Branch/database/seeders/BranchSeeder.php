<?php

namespace Modules\Branch\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;

class BranchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Branch::updateOrCreate(
            ['name' => 'Main Branch'],
            [
                'location' => '123 Main St, City, Country',

                'image' => 'branches/main_branch.jpg',
                'opening_hours' => '08:00 - 22:00',
                'map_coordinates' => '37.7749,-122.4194',
            ]
        );
    }
}
