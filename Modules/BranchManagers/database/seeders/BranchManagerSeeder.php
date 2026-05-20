<?php

namespace Modules\BranchManagers\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

class BranchManagerSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::first();

        if (! $branch) {
            $this->command->warn('Please seed branches first.');

            return;
        }

        // Create or update default branch manager
        BranchManager::updateOrCreate(
            ['email' => 'manager@assab.com'],
            [
                'name' => 'Ahmed Al-Saud',
                'phone' => '+966500000001',
                'password' => Hash::make('password123'),
                'branch_id' => $branch->id,
                'status' => 'active',
                'is_active' => true,
                'is_first_login' => false,
                'email_verified_at' => now(),
            ]
        );

        // Check if additional managers already exist
        $existingCount = BranchManager::where('email', '!=', 'manager@assab.com')->count();

        if ($existingCount < 2) {
            // Create additional managers only if they don't exist
            $needed = 2 - $existingCount;
            BranchManager::factory()->count($needed)->create([
                'branch_id' => $branch->id,
            ]);
        }

        $this->command->info('Branch Managers seeded successfully!');
        $this->command->info('Default Manager: manager@assab.com / password123');
    }
}
