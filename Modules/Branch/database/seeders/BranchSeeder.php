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
                'opening_hours' => '08:00:00',
                'closing_hours' => '22:00:00',
                'lat' => 37.7749,
                'lng' => -122.4194,
            ]
        );
        Branch::updateOrCreate(
            ['name' => 'Main Branch 2'],
            [
                'location' => '123 Main St, City, Country',

                'image' => 'branches/main_branch.jpg',
                'opening_hours' => '08:00:00',
                'closing_hours' => '22:00:00',
                'lat' => 37.7749,
                'lng' => -122.4194,
            ]
        );
    }
}
