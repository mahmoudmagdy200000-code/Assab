<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Models\Attachment;
use Modules\FixedAssets\Models\FixedAsset;

class AssetService
{
    public function __construct(
        private readonly TimelineService $timelineService,
    ) {}

    public function findForBranch(string $assetId, string $branchId): ?FixedAsset
    {
        return FixedAsset::query()
            ->with(['zone', 'assetType', 'assignedTo'])
            ->where('id', $assetId)
            ->where('branch_id', $branchId)
            ->first();
    }

    public function updateSettings(string $assetId, string $branchId, string $newZoneId, ?UploadedFile $newImage, BranchManager $manager): FixedAsset
    {
        return DB::transaction(function () use ($assetId, $branchId, $newZoneId, $newImage, $manager) {
            $asset = FixedAsset::query()
                ->where('id', $assetId)
                ->where('branch_id', $branchId)
                ->firstOrFail();

            $payload = [
                'zone_id' => $newZoneId,
                'last_updated_at' => now(),
            ];

            if ($newImage) {
                $previousImage = $asset->image;
                $payload['image'] = $newImage->store('fixed-assets/assets', 'public');

                if ($previousImage) {
                    Attachment::create([
                        'attachable_type' => $asset->getMorphClass(),
                        'attachable_id' => $asset->getKey(),
                        'kind' => 'asset_photo',
                        'file_name' => basename($previousImage),
                        'file_type' => 'image',
                        'file_size' => 0,
                        'path' => $previousImage,
                        'uploaded_by_id' => $manager->id,
                        'uploaded_at' => now(),
                    ]);
                }
            }

            $asset->update($payload);

            $this->timelineService->log(
                $asset,
                TimelineEventType::UPDATED,
                'Asset settings updated',
                $manager,
            );

            return $asset->fresh(['zone', 'assetType', 'assignedTo']);
        });
    }
}
