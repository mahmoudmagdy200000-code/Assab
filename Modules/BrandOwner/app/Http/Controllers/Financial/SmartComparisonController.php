<?php

namespace Modules\BrandOwner\Http\Controllers\Financial;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BrandOwner\Http\Requests\Financial\EmailSmartComparisonRequest;
use Modules\BrandOwner\Http\Requests\Financial\ExportSmartComparisonRequest;
use Modules\BrandOwner\Services\Financial\SmartComparisonService;

class SmartComparisonController extends BaseController
{
    public function __construct(
        private readonly SmartComparisonService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer'],
            'month' => ['nullable', 'integer'],
            'compared_year' => ['nullable', 'integer'],
            'compared_month' => ['nullable', 'integer'],
            'type' => ['required', 'in:month,branch'],
            // Both branch ids are optional — the service falls back to the DB default.
            'branch_id' => ['nullable', 'string'],
            'compared_branch_id' => ['nullable', 'string'],
        ]);

        return $this->successResponse(
            $this->service->comparison($validated),
            'Smart comparison retrieved successfully',
        );
    }

    public function export(ExportSmartComparisonRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->service->export($request->user(), $request->validated()),
            'Report exported successfully',
        );
    }

    public function email(EmailSmartComparisonRequest $request): JsonResponse
    {
        $this->service->email($request->validated());

        return $this->successResponse(null, 'Report emailed successfully');
    }
}
