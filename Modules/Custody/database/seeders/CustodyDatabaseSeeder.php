<?php

namespace Modules\Custody\Database\Seeders;

use Illuminate\Database\Seeder;

class CustodyDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            PersonalLedgerTransactionSeeder::class,
            CustodyRequestSeeder::class,
            CustodyTransactionSeeder::class,
            FixPersonalLedgerBalanceSeeder::class, // Fix negative balances
        ]);
    }
}
