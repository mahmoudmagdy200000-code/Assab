<?php

namespace Modules\Aggregator\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Aggregator\Database\Seeders\AggregatorSeeder;
use Modules\Aggregator\Entities\Aggregator;
use Modules\Aggregator\Http\Requests\CreateAggregatorRequest;
use Modules\Aggregator\Http\Requests\FilterAggregatorRequest;
use Modules\Aggregator\Http\Requests\UpdateAggregatorRequest;
use Modules\Aggregator\Http\Resources\AggregatorResource;
use Modules\Aggregator\Models\Aggregator as ModelsAggregator;
use Modules\Aggregator\Services\AggregatorService;
use Modules\Aggregator\Transformers\AggregatorDetailResource as TransformersAggregatorDetailResource;
use Modules\Aggregator\Transformers\AggregatorResource as TransformersAggregatorResource;

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
        $filters = $request->validated();

        /** @var LengthAwarePaginator $aggregators */
        $aggregators = $this->aggregatorService->getAggregators($filters);

        return $this->paginatedResponse(
            TransformersAggregatorResource::collection($aggregators),
            'Aggregators retrieved successfully'
        );
    }

    /**
     * Store a newly created aggregator
     */
    public function store(CreateAggregatorRequest $request): JsonResponse
    {
        $data = $request->validated();

        $aggregator = $this->aggregatorService->createAggregator($data);

        return response()->success(
            new TransformersAggregatorDetailResource($aggregator),
            'Aggregator created successfully',
            201
        );
    }

    /**
     * Display the specified aggregator
     */
    public function show(ModelsAggregator $aggregator): JsonResponse
    {
        $aggregatorDetails = $this->aggregatorService->getAggregatorDetails($aggregator->id);

        return $this->successResponse(
            new TransformersAggregatorDetailResource($aggregatorDetails),
            'Aggregator details retrieved successfully'
        );
    }

    /**
     * Update the specified aggregator
     */
    public function update(UpdateAggregatorRequest $request, Aggregator $aggregator): JsonResponse
    {
        $data = $request->validated();

        $updatedAggregator = $this->aggregatorService->updateAggregator($aggregator, $data);

        return $this->successResponse(
            new TransformersAggregatorDetailResource($updatedAggregator),
            'Aggregator updated successfully'
        );
    }

    /**
     * Remove the specified aggregator
     */
    public function destroy(AggregatorSeeder $aggregator): JsonResponse
    {
        $this->aggregatorService->deleteAggregator($aggregator);

        return response()->success(
            null,
            'Aggregator deleted successfully'
        );
    }

    /**
     * Activate the specified aggregator
     */
    public function activate(AggregatorSeeder $aggregator): JsonResponse
    {
        $aggregator->activate();

        return response()->success(
            new TransformersAggregatorResource($aggregator->fresh()),
            'Aggregator activated successfully'
        );
    }

    /**
     * Deactivate the specified aggregator
     */
    public function deactivate(Aggregator $aggregator): JsonResponse
    {
        $aggregator->deactivate();

        return response()->success(
            new AggregatorResource($aggregator->fresh()),
            'Aggregator deactivated successfully'
        );
    }

    /**
     * Get aggregator statistics
     */
    public function statistics(Aggregator $aggregator): JsonResponse
    {
        $stats = $aggregator->getAggregatorStatistics();

        return response()->success(
            $stats,
            'Aggregator statistics retrieved successfully'
        );
    }

    /**
     * Upload aggregator logo
     */
    public function uploadLogo(Request $request, Aggregator $aggregator): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,svg|max:2048',
        ]);

        $logoPath = $this->aggregatorService->uploadLogo(
            aggregator: $aggregator,
            logo: $request->file('logo')
        );

        return response()->success([
            'logo_url' => asset('storage/'.$logoPath),
        ], 'Aggregator logo uploaded successfully');
    }

    /**
     * Get all active aggregators (public endpoint)
     */
    public function available(): JsonResponse
    {
        $aggregators = ModelsAggregator::active()
            ->orderBy('name')
            ->get();

        return response()->success(
            TransformersAggregatorResource::collection($aggregators),
            'Available aggregators retrieved successfully'
        );
    }
}
