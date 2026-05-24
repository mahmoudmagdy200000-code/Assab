<?php

namespace Modules\BrandOwner\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\BrandOwner\Models\BrandManager;

class BrandManagerSeeder extends Seeder
{
    public function run(): void
    {
        BrandManager::updateOrCreate(
            ['email' => 'manager@assab.test'],
            [
                'name' => 'Brand Manager',
                'phone' => '+966500000002',
                'password' => Hash::make('Password@123'),
                'is_active' => true,
                'is_first_login' => false,
                'status' => 'active',
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]
        );
    }
}
