<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Controllers\Concerns\ResolvesInventoryActor;
use Modules\Inventory\Services\InventoryTaskListService;

/**
 * Unified "my tasks" endpoint for inventory assignments.
 * Cashiers see daily quick sessions, monthly inventories, and waste/damage reports assigned to them.
 */
class InventoryTaskController extends BaseController
{
    use ResolvesInventoryActor;

    public function __construct(
        private readonly InventoryTaskListService $taskListService
    ) {}

    /**
     * List tasks (assignments) for the authenticated user.
     * GET /inventory/tasks?limit=20
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $limit = (int) $request->query('limit', 20);
            $limit = $limit > 0 && $limit <= 50 ? $limit : 20;

            $tasks = $this->taskListService->getTasksForActor($actor->getActor(), $limit);

            return $this->successResponse($tasks, 'Tasks retrieved successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching tasks');
        }
    }
}
