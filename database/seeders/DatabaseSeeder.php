<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Aggregator\Database\Seeders\AggregatorSeeder;
use Modules\Aggregator\Database\Seeders\BranchAggregatorSeeder;
use Modules\Branch\Database\Seeders\BranchSeeder;
use Modules\BranchManagers\Database\Seeders\BranchManagerSeeder;
use Modules\Cashier\Database\Seeders\CashierSeeder;
use Modules\Cashier\Database\Seeders\CashierShiftSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call([
           BranchManagerSeeder::class,
            BranchSeeder::class,
            AggregatorSeeder::class,
            BranchAggregatorSeeder::class,
            CashierSeeder::class,
            BranchManagerSeeder::class,
            CashierShiftSeeder::class,

        ]);



    }
}
