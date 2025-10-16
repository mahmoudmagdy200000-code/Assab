<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Requests\BaseRequest;
use App\Http\Resources\BaseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Branch\Services\BranchService;

class BranchController extends BaseController
{
    public function __construct(
        private BranchService $branchService
    ) {}

    /**
     * Display a listing of branches
     */
    public function index(BranchRequest $request): JsonResponse
    {
        try {
            $filters = $request->getPaginationParams();
            $branches = $this->branchService->getBranches($filters);

            return $this->paginatedResponse(
                $branches,
                'Branches retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Branch listing');
        }
    }

    /**
     * Store a newly created branch
     */
    public function store(BranchRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $branch = $this->branchService->createBranch($data);

            return $this->createdResponse(
                new BranchResource($branch),
                'Branch created successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Branch creation');
        }
    }

    /**
     * Display the specified branch
     */
    public function show(Branch $branch): JsonResponse
    {
        try {
            $branchDetails = $this->branchService->getBranchDetails($branch->id);

            return $this->resourceResponse(
                new BranchResource($branchDetails),
                'Branch details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Branch details');
        }
    }

    /**
     * Update the specified branch
     */
    public function update(BranchRequest $request, Branch $branch): JsonResponse
    {
        try {
            $data = $request->validated();
            $updatedBranch = $this->branchService->updateBranch($branch, $data);

            return $this->updatedResponse(
                new BranchResource($updatedBranch),
                'Branch updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Branch update');
        }
    }

    /**
     * Remove the specified branch
     */
    public function destroy(Branch $branch): JsonResponse
    {
        try {
            // Check if branch has active managers or cashiers
            if ($branch->hasActiveUsers()) {
                return $this->conflictResponse(
                    'Cannot delete branch with active managers or cashiers'
                );
            }

            $this->branchService->deleteBranch($branch);

            return $this->deletedResponse('Branch deleted successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Branch deletion');
        }
    }

    /**
     * Get branch statistics
     */
    public function statistics(Branch $branch): JsonResponse
    {
        try {
            $stats = $this->branchService->getBranchStatistics($branch->id);

            return $this->successResponse(
                $stats,
                'Branch statistics retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Branch statistics');
        }
    }

    /**
     * Get available aggregators for branch
     */
    public function aggregators(Branch $branch): JsonResponse
    {
        try {
            $aggregators = $this->branchService->getAvailableAggregators($branch->id);

            return $this->collectionResponse(
                $aggregators,
                'Available aggregators retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Branch aggregators');
        }
    }
}

/**
 * Branch Request Class
 */
class BranchRequest extends BaseRequest
{
    public function rules(): array
    {
        $rules = array_merge(
            $this->getCommonRules(),
            $this->getPaginationRules(),
            $this->getSearchRules(),
            $this->getFileUploadRules()
        );

        if ($this->isCreating()) {
            $rules = array_merge($rules, [
                'name' => 'required|string|max:255|unique:branches,name',
                'location' => 'required|string|max:500',
                'opening_hours' => 'required|string|max:100',
                'map_coordinates' => 'sometimes|string|max:100',
            ]);
        } elseif ($this->isUpdating()) {
            $branchId = $this->getRouteParam('branch');
            $rules = array_merge($rules, [
                'name' => 'sometimes|required|string|max:255|unique:branches,name,' . $branchId,
                'location' => 'sometimes|required|string|max:500',
                'opening_hours' => 'sometimes|required|string|max:100',
                'map_coordinates' => 'sometimes|string|max:100',
            ]);
        }

        return $rules;
    }

    protected function getTableName(): string
    {
        return 'branches';
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'The branch name is required.',
            'name.unique' => 'A branch with this name already exists.',
            'location.required' => 'The branch location is required.',
            'opening_hours.required' => 'The opening hours are required.',
        ]);
    }
}

/**
 * Branch Resource Class
 */
class BranchResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->location,
            'image' => $this->formatImageUrl($this->image),
            'opening_hours' => $this->opening_hours,
            'map_coordinates' => $this->map_coordinates,
            'statistics' => $this->formatStatistics([
                'total' => $this->managers_count ?? 0,
                'active' => $this->active_managers_count ?? 0,
            ]),
            'managers' => $this->formatNestedCollection($this->whenLoaded('managers')),
            'cashiers' => $this->formatNestedCollection($this->whenLoaded('cashiers')),
            'aggregators' => $this->formatNestedCollection($this->whenLoaded('aggregators')),
            'timestamps' => $this->formatTimestamps(),
        ];
    }
}
