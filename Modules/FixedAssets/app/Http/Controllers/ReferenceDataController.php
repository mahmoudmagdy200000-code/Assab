<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\FixedAssets\Services\ReferenceDataService;
use Modules\FixedAssets\Transformers\AssetSearchItemResource;
use Modules\FixedAssets\Transformers\BranchResource;
use Modules\FixedAssets\Transformers\ResponsibleEmployeeResource;
use Modules\FixedAssets\Transformers\TypeResource;
use Modules\FixedAssets\Transformers\ZoneResource;

class ReferenceDataController extends BaseController
{
    public function __construct(
        private readonly ReferenceDataService $service,
    ) {}

    public function zones(): JsonResponse
    {
        $manager = auth()->user();
        $zones = $this->service->zones($manager->branch_id);

        return response()->json([
            'data' => ZoneResource::collection($zones)->resolve(),
        ]);
    }

    public function types(): JsonResponse
    {
        $types = $this->service->types();

        return response()->json([
            'data' => TypeResource::collection($types)->resolve(),
        ]);
    }

    public function employees(): JsonResponse
    {
        $manager = auth()->user();
        $employees = $this->service->employees($manager->branch_id);

        return response()->json([
            'data' => ResponsibleEmployeeResource::collection($employees)->resolve(),
        ]);
    }

    public function branches(): JsonResponse
    {
        $manager = auth()->user();
        $branches = $this->service->branches($manager->branch_id);

        return response()->json([
            'data' => BranchResource::collection($branches)->resolve(),
        ]);
    }

    public function assets(Request $request): JsonResponse
    {
        $manager = auth()->user();
        $assets = $this->service->assets(
            $manager->branch_id,
            $request->query('search'),
        );

        return response()->json([
            'data' => AssetSearchItemResource::collection($assets)->resolve(),
        ]);
    }
}
