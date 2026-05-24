<?php

namespace Modules\BrandOwner\Database\Seeders;

use Illuminate\Database\Seeder;

class BrandOwnerDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            BrandOwnerSeeder::class,
            BrandManagerSeeder::class,
        ]);
    }
}
