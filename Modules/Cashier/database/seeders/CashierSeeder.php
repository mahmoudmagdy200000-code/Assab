<?php

namespace Modules\Cashier\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Cashier\Models\Cashier;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

class CashierSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::first();
        $manager = BranchManager::first();

        if (!$branch || !$manager) {
            $this->command->warn('Please seed branches and branch managers first.');
            return;
        }


        // Create active cashiers
        Cashier::factory()->count(5)->active()->create([
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
        ]);

        // Create pending cashiers
        Cashier::factory()->count(2)->pending()->create([
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
        ]);

        $this->command->info('Cashiers seeded successfully!');
    }
}
