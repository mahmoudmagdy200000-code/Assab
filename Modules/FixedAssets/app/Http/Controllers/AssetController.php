<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Http\Requests\UpdateAssetSettingsRequest;
use Modules\FixedAssets\Services\AssetService;
use Modules\FixedAssets\Transformers\AssetSearchItemResource;

class AssetController extends BaseController
{
    public function __construct(
        private readonly AssetService $assetService,
    ) {}

    public function show(string $assetId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $asset = $this->assetService->findForBranch($assetId, $manager->branch_id);

        if (! $asset) {
            return $this->notFoundResponse('Asset not found');
        }

        return $this->successResponse(
            ['data' => (new AssetSearchItemResource($asset))->toArray(request())],
            'Asset details retrieved successfully',
        );
    }

    public function updateSettings(UpdateAssetSettingsRequest $request, string $assetId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $asset = $this->assetService->updateSettings(
            $assetId,
            $manager->branch_id,
            $request->input('new_zone_id'),
            $request->file('new_image'),
            $manager,
        );

        return $this->updatedResponse(
            (new AssetSearchItemResource($asset))->toArray($request),
            'Asset settings updated successfully',
        );
    }
}
