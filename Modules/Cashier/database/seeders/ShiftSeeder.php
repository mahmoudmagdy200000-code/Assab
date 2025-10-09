<?php

namespace Modules\Cashier\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\Cashier\Models\Shift;

class ShiftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $branches = Branch::all();

        if ($branches->isEmpty()) {
            $this->command->warn('⚠️ No branches found. Please seed branches first.');
            return;
        }

        foreach ($branches as $branch) {
            Shift::create([
                'name' => 'Morning Shift - ' . $branch->name,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'branch_id' => $branch->id,
                'is_active' => true,

                'created_at' => now(),
            ]);

            Shift::create([
                'name' => 'Evening Shift - ' . $branch->name,
                'start_time' => '16:00',
                'end_time' => '00:00',
                'branch_id' => $branch->id,
                'is_active' => true,
                
                'created_at' => now(),
            ]);
        }

        $this->command->info('✅ Shifts seeded successfully for all branches.');
    }
}
