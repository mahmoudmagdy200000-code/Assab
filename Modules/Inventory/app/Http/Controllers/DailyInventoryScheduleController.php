<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Http\Requests\DailyInventorySchedule\StoreDailyInventoryScheduleRequest;
use Modules\Inventory\Http\Requests\DailyInventorySchedule\UpdateDailyInventoryScheduleRequest;
use Modules\Inventory\Models\DailyInventorySchedule;
use Modules\Inventory\Services\DailyInventoryScheduleService;
use Modules\Inventory\Transformers\DailyInventoryScheduleResource;

class DailyInventoryScheduleController extends BaseController
{
    public function __construct(
        private readonly DailyInventoryScheduleService $scheduleService
    ) {}

    /**
     * Get daily inventory schedule for a branch (Account Manager).
     */
    public function show(string $branchId): JsonResponse
    {
        $this->authorize('viewAny', DailyInventorySchedule::class);

        $schedule = $this->scheduleService->getForBranch($branchId);
        if (! $schedule) {
            return $this->errorResponse('No daily inventory schedule found for this branch', 404);
        }

        return $this->successResponse(
            new DailyInventoryScheduleResource($schedule),
            'Daily inventory schedule retrieved successfully'
        );
    }

    /**
     * Create or replace daily inventory schedule for a branch (Account Manager).
     */
    public function store(StoreDailyInventoryScheduleRequest $request): JsonResponse
    {
        $this->authorize('create', DailyInventorySchedule::class);

        try {
            $schedule = $this->scheduleService->createOrUpdate(
                $request->validated('branch_id'),
                $request->validated(),
                auth()->id()
            );

            return $this->successResponse(
                new DailyInventoryScheduleResource($schedule),
                'Daily inventory schedule saved successfully',
                201
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'saving daily inventory schedule');
        }
    }

    /**
     * Update daily inventory schedule (add/remove items, update date/time). Affects only future tasks.
     */
    public function update(UpdateDailyInventoryScheduleRequest $request, string $id): JsonResponse
    {
        $schedule = DailyInventorySchedule::findOrFail($id);
        $this->authorize('update', $schedule);

        try {
            $schedule = $this->scheduleService->update($id, $request->validated());

            return $this->successResponse(
                new DailyInventoryScheduleResource($schedule),
                'Daily inventory schedule updated successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating daily inventory schedule');
        }
    }

    /**
     * Delete all daily inventory configuration for a branch. Stops new task generation; retains history.
     */
    public function destroy(string $branchId): JsonResponse
    {
        $schedule = $this->scheduleService->getForBranch($branchId);
        if ($schedule) {
            $this->authorize('delete', $schedule);
            $this->scheduleService->deleteForBranch($branchId);
        }

        return $this->successResponse(null, 'Daily inventory configuration deleted successfully');
    }
}
