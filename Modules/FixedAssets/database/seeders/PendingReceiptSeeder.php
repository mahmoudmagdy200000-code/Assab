<?php

namespace Modules\FixedAssets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\FixedAssets\Models\PendingReceipt;

class PendingReceiptSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->each(function (Branch $branch) {
            $samples = [
                ['name' => 'New Coffee Machine', 'code' => 'PR-COF-001', 'source' => 'from_finance'],
                ['name' => 'Walk-in Refrigerator', 'code' => 'PR-REF-002', 'source' => 'from_branch'],
                ['name' => 'Office Chair Set', 'code' => 'PR-FUR-003', 'source' => 'from_finance'],
            ];

            foreach ($samples as $s) {
                PendingReceipt::updateOrCreate(
                    [
                        'recipient_branch_id' => $branch->id,
                        'asset_code' => $s['code'],
                    ],
                    [
                        'asset_name' => $s['name'],
                        'asset_image' => null,
                        'source' => $s['source'],
                        'status' => 'pending',
                    ]
                );
            }
        });
    }
}
