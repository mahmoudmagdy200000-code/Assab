<?php

namespace Modules\FixedAssets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\DisposalMethod;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Enums\TransferDisposalKind;
use Modules\FixedAssets\Models\Attachment;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\Timeline;
use Modules\FixedAssets\Models\TransferDisposalItem;
use Modules\FixedAssets\Models\TransferDisposalRequest;

class DisposalRequestSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->each(function (Branch $branch) {
            $manager = BranchManager::where('branch_id', $branch->id)->first();
            $assets = FixedAsset::where('branch_id', $branch->id)->take(2)->get();

            if (! $manager || $assets->isEmpty()) {
                return;
            }

            $methods = [
                DisposalMethod::SCRAP->value,
                DisposalMethod::SELL->value,
                DisposalMethod::RETURN_TO_SUPPLIER->value,
            ];

            foreach ([true, false] as $i => $isApproved) {
                $req = TransferDisposalRequest::create([
                    'kind' => TransferDisposalKind::DISPOSAL->value,
                    'branch_id' => $branch->id,
                    'requested_by_id' => $manager->id,
                    'disposal_date' => now()->addDays(rand(3, 14))->toDateString(),
                    'disposal_time' => sprintf('%02d:00', rand(9, 17)),
                    'disposal_method' => $methods[$i % count($methods)],
                    'status' => $isApproved ? RequestStatus::APPROVED->value : RequestStatus::PENDING->value,
                    'approved_at' => $isApproved ? now()->subDays(rand(1, 3)) : null,
                ]);

                foreach ($assets as $asset) {
                    $item = TransferDisposalItem::create([
                        'request_id' => $req->id,
                        'asset_id' => $asset->id,
                        'disposal_reason' => 'End of useful life; cost of repair exceeds replacement.',
                        'condition_description' => 'Visible corrosion on the main body; intermittent electrical fault.',
                    ]);

                    Attachment::create([
                        'attachable_type' => $item->getMorphClass(),
                        'attachable_id' => $item->id,
                        'kind' => 'visual_evidence',
                        'file_name' => 'evidence_'.$item->id.'.jpg',
                        'file_type' => 'image/jpeg',
                        'file_size' => rand(120000, 600000),
                        'path' => 'fixed-assets/visual_evidence/sample-'.$item->id.'.jpg',
                        'uploaded_by_id' => $manager->id,
                        'uploaded_at' => now()->subDays(rand(0, 7)),
                    ]);
                }

                Timeline::create([
                    'timelineable_type' => $req->getMorphClass(),
                    'timelineable_id' => $req->id,
                    'event_type' => TimelineEventType::SUBMITTED->value,
                    'name' => 'Disposal request submitted',
                    'actor_image_path' => $manager->image,
                    'actor_id' => $manager->id,
                    'occurred_at' => now()->subDays(rand(2, 10)),
                ]);

                if ($isApproved) {
                    Timeline::create([
                        'timelineable_type' => $req->getMorphClass(),
                        'timelineable_id' => $req->id,
                        'event_type' => TimelineEventType::APPROVED->value,
                        'name' => 'Disposal approved',
                        'actor_image_path' => $manager->image,
                        'actor_id' => $manager->id,
                        'occurred_at' => now()->subDays(rand(0, 2)),
                    ]);
                }
            }
        });
    }
}
