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
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $zones = $this->service->zones($manager->branch_id);

        return $this->successResponse(
            ['data' => ZoneResource::collection($zones)->resolve()],
            'Zones retrieved successfully',
        );
    }

    public function types(): JsonResponse
    {
        $types = $this->service->types();

        return $this->successResponse(
            ['data' => TypeResource::collection($types)->resolve()],
            'Asset types retrieved successfully',
        );
    }

    public function employees(): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $employees = $this->service->employees($manager->branch_id);

        return $this->successResponse(
            ['data' => ResponsibleEmployeeResource::collection($employees)->resolve()],
            'Employees retrieved successfully',
        );
    }

    public function branches(): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $branches = $this->service->branches($manager->branch_id);

        return $this->successResponse(
            ['data' => BranchResource::collection($branches)->resolve()],
            'Branches retrieved successfully',
        );
    }

    public function assets(Request $request): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $assets = $this->service->assets(
            $manager->branch_id,
            $request->query('search'),
        );

        return $this->successResponse(
            ['data' => AssetSearchItemResource::collection($assets)->resolve()],
            'Assets retrieved successfully',
        );
    }
}
