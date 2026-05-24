<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BrandOwner\Services\BrandOwnerAssetOverviewService;

class BrandOwnerAssetOverviewController extends BaseController
{
    public function __construct(
        private readonly BrandOwnerAssetOverviewService $service,
    ) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            $this->service->overview(),
            'Asset overview retrieved successfully',
        );
    }

    public function performanceSummary(): JsonResponse
    {
        return $this->successResponse(
            $this->service->performanceSummary(),
            'Performance summary retrieved successfully',
        );
    }

    public function branchDetails(string $branchId): JsonResponse
    {
        try {
            $data = $this->service->branchDetails($branchId);
        } catch (ModelNotFoundException $e) {
            return $this->notFoundResponse($e->getMessage() ?: "Branch not found: {$branchId}");
        }

        return $this->successResponse($data, 'Branch details retrieved successfully');
    }

    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'string'],
            'format' => ['required', 'string'],
        ]);

        try {
            $data = $this->service->exportReport($validated['branch_id'], $validated['format']);
        } catch (ModelNotFoundException $e) {
            return $this->notFoundResponse($e->getMessage() ?: "Branch not found: {$validated['branch_id']}");
        }

        return $this->successResponse($data, 'Report exported successfully');
    }
}
