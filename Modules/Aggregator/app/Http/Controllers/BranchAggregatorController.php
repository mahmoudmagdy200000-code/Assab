<?php

namespace Modules\Aggregator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Aggregator\Entities\Aggregator;
use Modules\Aggregator\Services\BranchAggregatorService;

class BranchAggregatorController extends Controller
{
    public function __construct(
        private BranchAggregatorService $branchAggregatorService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('branch.manager');
    }

    /**
     * Get aggregators for a branch
     */
    public function getBranchAggregators(Request $request): JsonResponse
    {
        $manager = auth()->user();
        $branchId = $manager->branch_id;

        $aggregators = $this->branchAggregatorService->getBranchAggregators($branchId);

        return response()->success($aggregators, 'Branch aggregators retrieved successfully');
    }

    /**
     * Enable aggregator for branch
     */
    public function enable(Request $request, Aggregator $aggregator): JsonResponse
    {
        $manager = auth()->user();
        $branchId = $manager->branch_id;

        $this->branchAggregatorService->enableAggregator($branchId, $aggregator->id);

        return response()->success(null, 'Aggregator enabled successfully');
    }

    /**
     * Disable aggregator for branch
     */
    public function disable(Request $request, Aggregator $aggregator): JsonResponse
    {
        $manager = auth()->user();
        $branchId = $manager->branch_id;

        $this->branchAggregatorService->disableAggregator($branchId, $aggregator->id);

        return response()->success(null, 'Aggregator disabled successfully');
    }

    /**
     * Sync aggregators for branch
     */
    public function sync(Request $request): JsonResponse
    {
        $request->validate([
            'aggregator_ids' => 'required|array',
            'aggregator_ids.*' => 'exists:aggregators,id',
        ]);

        $manager = auth()->user();
        $branchId = $manager->branch_id;

        $result = $this->branchAggregatorService->syncBranchAggregators(
            $branchId,
            $request->aggregator_ids
        );

        return response()->success($result, 'Branch aggregators synchronized successfully');
    }
}
