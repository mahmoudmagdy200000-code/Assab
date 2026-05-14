<?php

namespace Modules\FixedAssets\Database\Seeders;

use Illuminate\Database\Seeder;

class FixedAssetsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AssetTypeSeeder::class,
            AssetZoneSeeder::class,
            FixedAssetSeeder::class,
            PendingReceiptSeeder::class,
            ModificationRequestSeeder::class,
            TransferRequestSeeder::class,
            DisposalRequestSeeder::class,
        ]);
    }
}
