<?php

namespace Modules\Aggregator\Http\Controllers;


use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Aggregator\Entities\Aggregator;
use Modules\Aggregator\Services\AggregatorService;
use Modules\Aggregator\Http\Requests\CreateAggregatorRequest;
use Modules\Aggregator\Http\Requests\UpdateAggregatorRequest;
use Modules\Aggregator\Http\Requests\FilterAggregatorRequest;
use Modules\Aggregator\Http\Resources\AggregatorResource;
use Modules\Aggregator\Http\Resources\AggregatorDetailResource;

class AggregatorController extends Controller
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

        $aggregators = $this->aggregatorService->getAggregators($filters);

        return response()->success(
            AggregatorResource::collection($aggregators),
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
            new AggregatorDetailResource($aggregator),
            'Aggregator created successfully',
            201
        );
    }

    /**
     * Display the specified aggregator
     */
    public function show(Aggregator $aggregator): JsonResponse
    {
        $aggregatorDetails = $this->aggregatorService->getAggregatorDetails($aggregator->id);

        return response()->success(
            new AggregatorDetailResource($aggregatorDetails),
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

        return response()->success(
            new AggregatorDetailResource($updatedAggregator),
            'Aggregator updated successfully'
        );
    }

    /**
     * Remove the specified aggregator
     */
    public function destroy(Aggregator $aggregator): JsonResponse
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
    public function activate(Aggregator $aggregator): JsonResponse
    {
        $aggregator->activate();

        return response()->success(
            new AggregatorResource($aggregator->fresh()),
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
            'logo_url' => asset('storage/' . $logoPath),
        ], 'Aggregator logo uploaded successfully');
    }

    /**
     * Get all active aggregators (public endpoint)
     */
    public function available(): JsonResponse
    {
        $aggregators = Aggregator::active()
            ->orderBy('name')
            ->get();

        return response()->success(
            AggregatorResource::collection($aggregators),
            'Available aggregators retrieved successfully'
        );
    }
}

