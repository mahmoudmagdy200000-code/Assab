<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\TimelineEventType;
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
                $payload['image'] = $newImage->store('fixed-assets/assets', 'public');
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
