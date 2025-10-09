<?php

namespace Modules\Cashier\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Cashier\Models\Cashier;

class CashierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
       Cashier::updateOrCreate(
            ['email' => 'branchmanager@test.com'],
            [
                'name' => 'Branch Manager',
                'password' => bcrypt('password'),
                'phone' => '1234567890',
                'status' => 'active',
                'branch_id' => 1,
                'created_by' => 1,
                'updated_by' => 1,
                'image' => null,
                'activated_at' => now(),
                'deactivated_at' => null,

            ]
        );
    }
}
