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
        $manager = auth()->user();
        $asset = $this->assetService->findForBranch($assetId, $manager->branch_id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => 'Asset not found.',
            ], 404);
        }

        return response()->json([
            'data' => (new AssetSearchItemResource($asset))->toArray(request()),
        ]);
    }

    public function updateSettings(UpdateAssetSettingsRequest $request, string $assetId): JsonResponse
    {
        $manager = auth()->user();
        $asset = $this->assetService->updateSettings(
            $assetId,
            $manager->branch_id,
            $request->input('new_zone_id'),
            $request->file('new_image'),
            $manager,
        );

        return response()->json([
            'success' => true,
            'message' => 'Asset settings updated successfully',
            'data' => (new AssetSearchItemResource($asset))->toArray($request),
        ]);
    }
}
