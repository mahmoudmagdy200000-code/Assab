<?php

namespace Modules\BranchManagers\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\BranchManagers\Models\BranchManager;

class BranchManagerSeeder extends Seeder
{
    public function run()
    {
        BranchManager::updateOrCreate(
            ['email' => 'branchmanager@test.com'],
            [
                'name' => 'Test Branch Manager',
                'email' => 'branchmanager@test.com',
                'phone' => '+201234567890',
                'password' => Hash::make('Password123'),
                'is_first_login' => true,
            ]
        );

        $this->command->info('✅ Branch Manager test account created:');
        $this->command->info('Email: branchmanager@test.com');
        $this->command->info('Password: Password123');
    }
}
