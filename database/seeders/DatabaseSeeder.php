<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Aggregator\Database\Seeders\AggregatorSeeder;
use Modules\Aggregator\Database\Seeders\BranchAggregatorSeeder;
use Modules\Branch\Database\Seeders\BranchSeeder;
use Modules\BrandOwner\Database\Seeders\BrandOwnerSeeder;
use Modules\BranchManagers\Database\Seeders\BranchManagerSeeder;
use Modules\Cashier\Database\Seeders\CashierSeeder;
use Modules\Cashier\Database\Seeders\CashierShiftSeeder;
use Modules\Expense\Database\Seeders\CategorySeeder;
use Modules\Expense\Database\Seeders\ExpenseSeeder;
use Modules\Expense\Database\Seeders\SupplierSeeder;
use Modules\Shift\Database\Seeders\ShiftSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call([
            // 1) Core references
            BranchSeeder::class,

            // 2) Users tied to branches
            BranchManagerSeeder::class,

            // 3) Operational structures tied to branches
            ShiftSeeder::class,

            // 4) Cashiers and their shifts (require branches/managers/shifts)
            CashierSeeder::class,
            CashierShiftSeeder::class,

            // 5) Aggregators and branch linkage (require branches)
            AggregatorSeeder::class,
            BranchAggregatorSeeder::class,
             CategorySeeder::class,
            SupplierSeeder::class,
            ExpenseSeeder::class,

            // 6) Brand Owner account
            BrandOwnerSeeder::class,
        ]);
    }
}
