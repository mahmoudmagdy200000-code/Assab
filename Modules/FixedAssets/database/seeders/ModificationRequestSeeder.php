<?php

namespace Modules\FixedAssets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Enums\ModificationDoneAction as DoneActionEnum;
use Modules\FixedAssets\Enums\ModificationNextAction;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Models\Attachment;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\ModificationDoneAction;
use Modules\FixedAssets\Models\ModificationRequest;
use Modules\FixedAssets\Models\Timeline;

class ModificationRequestSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->each(function (Branch $branch) {
            $manager = BranchManager::where('branch_id', $branch->id)->first();
            $assets = FixedAsset::where('branch_id', $branch->id)->take(4)->get();

            if (! $manager || $assets->isEmpty()) {
                return;
            }

            foreach ($assets as $i => $asset) {
                $isApproved = $i % 2 === 0;

                $req = ModificationRequest::create([
                    'asset_id' => $asset->id,
                    'branch_id' => $branch->id,
                    'requested_by_id' => $manager->id,
                    'status' => $isApproved ? RequestStatus::APPROVED->value : RequestStatus::PENDING->value,
                    'new_status' => AssetStatus::NEED_ATTENTION->value,
                    'reason' => 'Detected wear during routine check; requires technician review.',
                    'next_action' => ModificationNextAction::NEED_TECHNICIAN->value,
                    'approval_request_owner_note' => 'Please review and approve at earliest convenience.',
                    'approved_at' => $isApproved ? now()->subDays(rand(1, 5)) : null,
                ]);

                ModificationDoneAction::create([
                    'modification_request_id' => $req->id,
                    'action' => DoneActionEnum::INITIAL_CHECK->value,
                ]);

                if ($i % 2 === 1) {
                    ModificationDoneAction::create([
                        'modification_request_id' => $req->id,
                        'action' => DoneActionEnum::CLEANING->value,
                    ]);
                }

                Attachment::create([
                    'attachable_type' => $req->getMorphClass(),
                    'attachable_id' => $req->id,
                    'kind' => 'modification_doc',
                    'file_name' => 'modification_doc_'.$req->id.'.jpg',
                    'file_type' => 'image/jpeg',
                    'file_size' => rand(50000, 500000),
                    'path' => 'fixed-assets/modification_doc/sample-'.$req->id.'.jpg',
                    'uploaded_by_id' => $manager->id,
                    'uploaded_at' => now()->subDays(rand(0, 7)),
                ]);

                Timeline::create([
                    'timelineable_type' => $req->getMorphClass(),
                    'timelineable_id' => $req->id,
                    'event_type' => TimelineEventType::SUBMITTED->value,
                    'name' => 'Modification request submitted',
                    'actor_image_path' => $manager->image,
                    'actor_id' => $manager->id,
                    'occurred_at' => now()->subDays(rand(2, 10)),
                ]);

                if ($isApproved) {
                    Timeline::create([
                        'timelineable_type' => $req->getMorphClass(),
                        'timelineable_id' => $req->id,
                        'event_type' => TimelineEventType::APPROVED->value,
                        'name' => 'Modification request approved',
                        'actor_image_path' => $manager->image,
                        'actor_id' => $manager->id,
                        'occurred_at' => now()->subDays(rand(0, 2)),
                    ]);
                }
            }
        });
    }
}
