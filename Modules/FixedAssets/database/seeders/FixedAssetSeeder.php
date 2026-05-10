<?php

namespace Modules\FixedAssets\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Models\AssetType;
use Modules\FixedAssets\Models\AssetZone;
use Modules\FixedAssets\Models\FixedAsset;

class FixedAssetSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = AssetStatus::cases();

        Branch::query()->each(function (Branch $branch) use ($statuses) {
            $zones = AssetZone::where('branch_id', $branch->id)->get();
            $types = AssetType::all();
            $manager = BranchManager::where('branch_id', $branch->id)->first();

            if ($zones->isEmpty() || $types->isEmpty()) {
                return;
            }

            for ($i = 0; $i < 20; $i++) {
                $type = $types->random();
                $zone = $zones->random();
                $status = $statuses[array_rand($statuses)];

                FixedAsset::updateOrCreate(
                    ['code' => 'FA-'.strtoupper(Str::random(8))],
                    [
                        'name' => $type->name.' #'.($i + 1),
                        'image' => null,
                        'branch_id' => $branch->id,
                        'zone_id' => $zone->id,
                        'asset_type_id' => $type->id,
                        'assigned_to_type' => $manager ? BranchManager::class : null,
                        'assigned_to_id' => $manager?->id,
                        'status' => $status->value,
                        'value' => rand(500, 25000),
                        'acquired_at' => now()->subMonths(rand(1, 36)),
                        'custody_started_at' => now()->subMonths(rand(1, 12)),
                        'last_updated_at' => now()->subDays(rand(0, 90)),
                    ]
                );
            }
        });
    }
}
