<?php

namespace Modules\Aggregator\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Aggregator\Http\Requests\CreateAggregatorRequest;
use Modules\Aggregator\Http\Requests\UpdateAggregatorRequest;
use Modules\Aggregator\Http\Requests\FilterAggregatorRequest;
use Modules\Aggregator\Models\Aggregator;
use Modules\Aggregator\Services\AggregatorService;
use Modules\Aggregator\Transformers\AggregatorResource;
use Modules\Aggregator\Transformers\AggregatorDetailResource;

class AggregatorController extends BaseController
{
    public function __construct(
        private AggregatorService $aggregatorService
    ) {
        $this->middleware('auth:sanctum');
    }

    /**
     * Display a listing of aggregators
     */
    public function index(FilterAggregatorRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $aggregators = $this->aggregatorService->getAggregators($filters);

            return $this->paginatedResponse(
                $aggregators,
                'Aggregators retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Aggregator listing');
        }
    }

    /**
     * Store a newly created aggregator
     */
    public function store(CreateAggregatorRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $aggregator = $this->aggregatorService->createAggregator($data);

            return $this->createdResponse(
                new AggregatorDetailResource($aggregator),
                'Aggregator created successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Aggregator creation');
        }
    }

    /**
     * Display the specified aggregator
     */
    public function show(Aggregator $aggregator): JsonResponse
    {
        try {
            $aggregatorDetails = $this->aggregatorService->getAggregatorDetails($aggregator->id);

            return $this->resourceResponse(
                new AggregatorDetailResource($aggregatorDetails),
                'Aggregator details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Aggregator details');
        }
    }

    /**
     * Update the specified aggregator
     */
    public function update(UpdateAggregatorRequest $request, Aggregator $aggregator): JsonResponse
    {
        try {
            $data = $request->validated();
            $updatedAggregator = $this->aggregatorService->updateAggregator($aggregator, $data);

            return $this->updatedResponse(
                new AggregatorDetailResource($updatedAggregator),
                'Aggregator updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Aggregator update');
        }
    }

    /**
     * Remove the specified aggregator
     */
    public function destroy(Aggregator $aggregator): JsonResponse
    {
        try {
            // Check if aggregator has active branches
            if ($aggregator->hasActiveBranches()) {
                return $this->conflictResponse(
                    'Cannot delete aggregator with active branches'
                );
            }

            $this->aggregatorService->deleteAggregator($aggregator);

            return $this->deletedResponse('Aggregator deleted successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Aggregator deletion');
        }
    }

    /**
     * Activate the specified aggregator
     */
    public function activate(Aggregator $aggregator): JsonResponse
    {
        try {
            if ($aggregator->is_active) {
                return $this->errorResponse('Aggregator is already active', 400);
            }

            $this->aggregatorService->activateAggregator($aggregator);

            return $this->updatedResponse(
                new AggregatorResource($aggregator->fresh()),
                'Aggregator activated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Aggregator activation');
        }
    }

    /**
     * Deactivate the specified aggregator
     */
    public function deactivate(Aggregator $aggregator): JsonResponse
    {
        try {
            if (!$aggregator->is_active) {
                return $this->errorResponse('Aggregator is already inactive', 400);
            }

            $this->aggregatorService->deactivateAggregator($aggregator);

            return $this->updatedResponse(
                new AggregatorResource($aggregator->fresh()),
                'Aggregator deactivated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Aggregator deactivation');
        }
    }

    /**
     * Get aggregator statistics
     */
    public function statistics(Aggregator $aggregator): JsonResponse
    {
        try {
            $stats = $this->aggregatorService->getAggregatorStatistics($aggregator->id);

            return $this->successResponse(
                $stats,
                'Aggregator statistics retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Aggregator statistics');
        }
    }

    /**
     * Upload aggregator logo
     */
    public function uploadLogo(Request $request, Aggregator $aggregator): JsonResponse
    {
        try {
            $request->validate([
                'logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:1024'
            ]);

            $logoPath = $this->aggregatorService->uploadLogo($aggregator, $request->file('logo'));

            return $this->updatedResponse(
                ['logo_url' => asset('storage/' . $logoPath)],
                'Logo uploaded successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Logo upload');
        }
    }

    /**
     * Get available aggregators (public endpoint)
     */
    public function available(): JsonResponse
    {
        try {
            $aggregators = $this->aggregatorService->getAvailableAggregators();

            return $this->collectionResponse(
                AggregatorResource::collection($aggregators),
                'Available aggregators retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Available aggregators');
        }
    }
}
