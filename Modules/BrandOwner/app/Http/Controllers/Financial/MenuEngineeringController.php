<?php

namespace Modules\BrandOwner\Http\Controllers\Financial;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BrandOwner\Http\Requests\Financial\EmailMenuEngineeringRequest;
use Modules\BrandOwner\Http\Requests\Financial\ExportMenuEngineeringRequest;
use Modules\BrandOwner\Services\Financial\MenuEngineeringService;

class MenuEngineeringController extends BaseController
{
    public function __construct(
        private readonly MenuEngineeringService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->service->build(
            $request->integer('year') ?: null,
            $request->integer('month') ?: null,
            $request->integer('compared_year') ?: null,
            $request->integer('compared_month') ?: null,
            $request->query('branch_id'),
        );

        return $this->successResponse($data, 'Menu engineering report retrieved successfully');
    }

    public function export(ExportMenuEngineeringRequest $request): JsonResponse
    {
        $result = $this->service->export(auth()->user(), $request->validated());

        return $this->successResponse($result, 'Report exported successfully');
    }

    public function email(EmailMenuEngineeringRequest $request): JsonResponse
    {
        $this->service->email($request->validated());

        return $this->successResponse(null, 'Report emailed successfully');
    }
}
