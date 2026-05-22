<?php

namespace Modules\BranchManagers\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Modules\BranchManagers\Exceptions\BranchManagerSettingsException;
use Modules\BranchManagers\Http\Requests\Settings\AddAggregatorRequest;
use Modules\BranchManagers\Http\Requests\Settings\ToggleEnabledRequest;
use Modules\BranchManagers\Services\BranchManagerAggregatorService;
use Modules\BranchManagers\Transformers\Settings\SettingsAggregatorResource;

/**
 * Manages the aggregators added to the authenticated branch manager's branch.
 */
class BranchManagerSettingsAggregatorController extends BaseController
{
    public function __construct(
        private readonly BranchManagerAggregatorService $aggregatorService,
    ) {}

    /**
     * GET /branch-manager/settings/aggregators/available
     */
    public function available(): JsonResponse
    {
        $aggregators = $this->aggregatorService->available($this->branchId());

        return $this->successResponse(
            SettingsAggregatorResource::collection($aggregators),
            'Available aggregators retrieved successfully',
        );
    }

    /**
     * GET /branch-manager/settings/aggregators/assigned
     */
    public function assigned(): JsonResponse
    {
        $aggregators = $this->aggregatorService->assigned($this->branchId());

        return $this->successResponse(
            SettingsAggregatorResource::collection($aggregators),
            'Branch aggregators retrieved successfully',
        );
    }

    /**
     * POST /branch-manager/settings/aggregators
     */
    public function store(AddAggregatorRequest $request): JsonResponse
    {
        try {
            $aggregator = $this->aggregatorService->add(
                $this->branchId(),
                $request->validated('aggregator_id'),
            );
        } catch (BranchManagerSettingsException $e) {
            return $this->errorResponse($e->getMessage(), $e->status());
        }

        return $this->createdResponse(
            new SettingsAggregatorResource($aggregator),
            'Aggregator added successfully',
        );
    }

    /**
     * DELETE /branch-manager/settings/aggregators/{aggregatorId}
     */
    public function destroy(string $aggregatorId): JsonResponse
    {
        try {
            $aggregator = $this->aggregatorService->remove($this->branchId(), $aggregatorId);
        } catch (BranchManagerSettingsException $e) {
            return $this->errorResponse($e->getMessage(), $e->status());
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Aggregator not found');
        }

        return $this->successResponse(
            new SettingsAggregatorResource($aggregator),
            'Aggregator removed successfully',
        );
    }

    /**
     * PATCH /branch-manager/settings/aggregators/{aggregatorId}/status
     */
    public function updateStatus(string $aggregatorId, ToggleEnabledRequest $request): JsonResponse
    {
        try {
            $aggregator = $this->aggregatorService->setEnabled(
                $this->branchId(),
                $aggregatorId,
                $request->enabled(),
            );
        } catch (BranchManagerSettingsException $e) {
            return $this->errorResponse($e->getMessage(), $e->status());
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Aggregator not found');
        }

        return $this->successResponse(
            new SettingsAggregatorResource($aggregator),
            'Aggregator status updated successfully',
        );
    }

    /**
     * Branch of the authenticated manager (guaranteed by branch.manager middleware).
     */
    private function branchId(): string
    {
        return auth()->user()->branch_id;
    }
}
