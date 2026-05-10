<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Http\Requests\AssetsSearchByImageRequest;
use Modules\FixedAssets\Http\Requests\AssetsSearchRequest;
use Modules\FixedAssets\Services\AssetSearchService;
use Modules\FixedAssets\Transformers\AssetSearchItemResource;
use Modules\FixedAssets\Transformers\SearchSummaryResource;

class AssetSearchController extends BaseController
{
    public function __construct(
        private readonly AssetSearchService $service,
    ) {}

    public function search(AssetsSearchRequest $request): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $result = $this->service->search($request->validated(), $manager->branch_id, $manager->id);

        return $this->successResponse(
            [
                'asset_type_name' => $result['asset_type_name'],
                'data' => [
                    'summary' => (new SearchSummaryResource($result['summary']))->toArray($request),
                    'items' => AssetSearchItemResource::collection($result['items'])->resolve(),
                ],
            ],
            'Search results retrieved successfully',
        );
    }

    public function searchByImage(AssetsSearchByImageRequest $request): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $result = $this->service->searchByImage($manager->branch_id);

        return $this->successResponse(
            [
                'asset_type_name' => $result['asset_type_name'],
                'data' => [
                    'summary' => (new SearchSummaryResource($result['summary']))->toArray($request),
                    'items' => AssetSearchItemResource::collection($result['items'])->resolve(),
                ],
            ],
            'Search results retrieved successfully',
        );
    }
}
