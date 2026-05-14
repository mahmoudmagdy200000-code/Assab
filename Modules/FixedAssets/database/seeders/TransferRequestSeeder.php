<?php

namespace Modules\FixedAssets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Enums\TransferDirection;
use Modules\FixedAssets\Enums\TransferDisposalKind;
use Modules\FixedAssets\Models\Attachment;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\Timeline;
use Modules\FixedAssets\Models\TransferDisposalItem;
use Modules\FixedAssets\Models\TransferDisposalRequest;

class TransferRequestSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();
        if ($branches->count() < 2) {
            return;
        }

        $branches->each(function (Branch $branch) use ($branches) {
            $manager = BranchManager::where('branch_id', $branch->id)->first();
            $assets = FixedAsset::where('branch_id', $branch->id)->take(3)->get();
            $recipient = $branches->where('id', '!=', $branch->id)->first();

            if (! $manager || $assets->isEmpty() || ! $recipient) {
                return;
            }

            foreach ([true, false] as $i => $isApproved) {
                $req = TransferDisposalRequest::create([
                    'kind' => TransferDisposalKind::TRANSFER_TO_BRANCH->value,
                    'branch_id' => $branch->id,
                    'requested_by_id' => $manager->id,
                    'recipient_branch_id' => $recipient->id,
                    'auto_approve' => false,
                    'direction' => TransferDirection::TO_BRANCH->value,
                    'status' => $isApproved ? RequestStatus::APPROVED->value : RequestStatus::PENDING->value,
                    'approved_at' => $isApproved ? now()->subDays(rand(1, 3)) : null,
                ]);

                foreach ($assets->take(2) as $asset) {
                    $item = TransferDisposalItem::create([
                        'request_id' => $req->id,
                        'asset_id' => $asset->id,
                        'transfer_reason' => 'Branch '.$recipient->name.' needs equipment for new opening.',
                    ]);

                    Attachment::create([
                        'attachable_type' => $item->getMorphClass(),
                        'attachable_id' => $item->id,
                        'kind' => 'documentation_photo',
                        'file_name' => 'doc_'.$item->id.'.jpg',
                        'file_type' => 'image/jpeg',
                        'file_size' => rand(80000, 400000),
                        'path' => 'fixed-assets/documentation_photo/sample-'.$item->id.'.jpg',
                        'uploaded_by_id' => $manager->id,
                        'uploaded_at' => now()->subDays(rand(0, 7)),
                    ]);
                }

                Timeline::create([
                    'timelineable_type' => $req->getMorphClass(),
                    'timelineable_id' => $req->id,
                    'event_type' => TimelineEventType::SUBMITTED->value,
                    'name' => 'Transfer request submitted',
                    'actor_image_path' => $manager->image,
                    'actor_id' => $manager->id,
                    'occurred_at' => now()->subDays(rand(2, 10)),
                ]);

                if ($isApproved) {
                    Timeline::create([
                        'timelineable_type' => $req->getMorphClass(),
                        'timelineable_id' => $req->id,
                        'event_type' => TimelineEventType::APPROVED->value,
                        'name' => 'Transfer approved',
                        'actor_image_path' => $manager->image,
                        'actor_id' => $manager->id,
                        'occurred_at' => now()->subDays(rand(0, 2)),
                    ]);
                }
            }
        });
    }
}
