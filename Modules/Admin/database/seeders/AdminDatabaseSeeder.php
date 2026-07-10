<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;

class AdminDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AsabRolePermissionSeeder::class,
            AsabBrandPackageSeeder::class,
            AsabDemoSeeder::class,
            AsabOperationSeeder::class,
            CompanyDashboardSeeder::class,
        ]);
    }
}
