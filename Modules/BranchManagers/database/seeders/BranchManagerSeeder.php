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

        // Clear existing branch managers (optional - remove if you want to keep existing data)
        BranchManager::truncate();

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

        // Create additional managers
        BranchManager::factory()->count(2)->create([
            'branch_id' => $branch->id,
        ]);

        $this->command->info('Branch Managers seeded successfully!');
        $this->command->info('Default Manager: manager@assab.com / password123');
    }
}
