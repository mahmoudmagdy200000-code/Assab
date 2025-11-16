<?php

namespace Modules\BranchManagers\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Branch\Models\Branch;
use Illuminate\Support\Facades\Hash;

class BranchManagerSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::first();

        if (!$branch) {
            $this->command->warn('Please seed branches first.');
            return;
        }

        // Create default branch manager
        BranchManager::create([
            'name' => 'Ahmed Al-Saud',
            'email' => 'manager@assab.com',
            'phone' => '+966500000001',
            'password' => Hash::make('password123'),
            'branch_id' => $branch->id,
            'status' => 'active',
            'is_active' => true,
            'is_first_login' => false,
            'email_verified_at' => now(),
        ]);

        // ➕ Create your custom manager
        BranchManager::create([
            'name' => 'Mohamed Ali',
            'email' => 'mohamedali.coder@gmail.com',
            'phone' => '01020399344',
            'password' => Hash::make('password'),
            'branch_id' => $branch->id,
            'role' => 'branch_manager',
            'status' => 'active',
            'is_active' => true,
            'is_first_login' => true,
            'email_verified_at' => null,
            'phone_verified_at' => null,
        ]);

        // Create additional managers
        BranchManager::factory()->count(2)->create([
            'branch_id' => $branch->id,
        ]);

        $this->command->info('Branch Managers seeded successfully!');
        $this->command->info('Default Manager: manager@assab.com / password123');
        $this->command->info('Added Manager: mohamedali.coder@gmail.com / password');
    }
}
