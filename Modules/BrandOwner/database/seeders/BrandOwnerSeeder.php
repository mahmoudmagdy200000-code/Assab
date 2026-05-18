<?php

namespace Modules\BrandOwner\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\BrandOwner\Models\BrandOwner;

class BrandOwnerSeeder extends Seeder
{
    public function run(): void
    {
        BrandOwner::updateOrCreate(
            ['email' => 'owner@assab.test'],
            [
                'name'              => 'Brand Owner',
                'phone'             => '+966500000001',
                'password'          => Hash::make('Password@123'),
                'is_active'         => true,
                'is_first_login'    => false,
                'status'            => 'active',
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]
        );
    }
}
