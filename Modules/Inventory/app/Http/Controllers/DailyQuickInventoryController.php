<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Http\Requests\AddInventoryItemRequest;
use Modules\Inventory\Http\Requests\CreateInventorySessionRequest;
use Modules\Inventory\Http\Requests\UpdateInventoryItemRequest;
use Modules\Inventory\Http\Requests\UpdateInventorySessionRequest;
use Modules\Inventory\Models\InventorySession;
use Modules\Inventory\Services\InventorySessionService;
use Modules\Inventory\Transformers\InventoryItemResource;
use Modules\Inventory\Transformers\InventorySessionResource;
use Modules\Inventory\Transformers\InventorySessionSummaryResource;

class DailyQuickInventoryController extends BaseController
{
    public function __construct(
        private readonly InventorySessionService $sessionService
    ) {}

    /**
     * Get closed order items for inventory
     *
     * @group Daily Quick Inventory
     */
    public function getClosedOrderItems(): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            if (!$manager->branch_id) {
                return $this->errorResponse('Branch manager is not assigned to any branch', 400);
            }

            $items = $this->sessionService->getClosedOrderItems($manager->branch_id);

            return $this->successResponse(
                $items,
                'Closed order items retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching closed order items');
        }
    }

    /**
     * Get available employees (cashiers)
     *
     * @group Daily Quick Inventory
     */
    public function getEmployees(): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            if (!$manager->branch_id) {
                return $this->errorResponse('Branch manager is not assigned to any branch', 400);
            }

            $cashiers = $this->sessionService->getAvailableCashiers($manager->branch_id);

            return $this->successResponse(
                $cashiers,
                'Employees retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching employees');
        }
    }

    /**
     * Create a new inventory session (draft)
     *
     * @group Daily Quick Inventory
     */
    public function createSession(CreateInventorySessionRequest $request): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            if (!$manager->branch_id) {
                return $this->errorResponse('Branch manager is not assigned to any branch', 400);
            }

            $session = $this->sessionService->createDraft($request->validated(), $manager);

            return $this->successResponse(
                new InventorySessionResource($session->load(['items.item', 'items.purchaseOrderItem.purchaseOrder', 'assignedTo'])),
                'Inventory session created successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating inventory session');
        }
    }

    /**
     * Update inventory session
     *
     * @group Daily Quick Inventory
     */
    public function updateSession(UpdateInventorySessionRequest $request, string $id): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            $session = $this->sessionService->updateDraft($id, $request->validated(), $manager);

            return $this->successResponse(
                new InventorySessionResource($session->load(['items', 'assignedTo'])),
                'Inventory session updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating inventory session');
        }
    }

    /**
     * Add item to inventory session
     *
     * @group Daily Quick Inventory
     */
    public function addItem(AddInventoryItemRequest $request, string $id): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            $item = $this->sessionService->addItem($id, $request->validated(), $manager);

            return $this->successResponse(
                new InventoryItemResource($item->load(['item', 'purchaseOrderItem.purchaseOrder'])),
                'Item added to inventory session successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'adding item to inventory session');
        }
    }

    /**
     * Update inventory item
     *
     * @group Daily Quick Inventory
     */
    public function updateItem(UpdateInventoryItemRequest $request, string $sessionId, string $itemId): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            $item = $this->sessionService->updateItem($itemId, $request->validated(), $manager);

            return $this->successResponse(
                new InventoryItemResource($item),
                'Inventory item updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating inventory item');
        }
    }

    /**
     * Remove item from inventory session
     *
     * @group Daily Quick Inventory
     */
    public function removeItem(string $sessionId, string $itemId): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            $this->sessionService->removeItem($itemId, $manager);

            return $this->successResponse(
                null,
                'Item removed from inventory session successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'removing item from inventory session');
        }
    }

    /**
     * Submit inventory session
     *
     * @group Daily Quick Inventory
     */
    public function submitSession(string $id): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            $session = $this->sessionService->submitSession($id, $manager);

            return $this->successResponse(
                new InventorySessionResource($session),
                'Inventory session submitted successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'submitting inventory session');
        }
    }

    /**
     * Get session summary
     *
     * @group Daily Quick Inventory
     */
    public function getSessionSummary(string $id): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            $summary = $this->sessionService->getSessionSummary($id, $manager);

            return $this->successResponse(
                new InventorySessionSummaryResource($summary),
                'Session summary retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching session summary');
        }
    }

    /**
     * Get all inventory sessions
     *
     * @group Daily Quick Inventory
     */
    public function getSessions(): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            if (!$manager->branch_id) {
                return $this->errorResponse('Branch manager is not assigned to any branch', 400);
            }

            $sessions = InventorySession::where('branch_id', $manager->branch_id)
                ->where('created_by', $manager->id)
                ->with(['items', 'assignedTo'])
                ->withCount('items')
                ->orderBy('created_at', 'desc')
                ->paginate(request()->get('per_page', 15));

            return $this->paginatedResponse(
                InventorySessionResource::collection($sessions),
                'Inventory sessions retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching inventory sessions');
        }
    }

    /**
     * Get inventory session details
     *
     * @group Daily Quick Inventory
     */
    public function getSession(string $id): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            $session = InventorySession::where('id', $id)
                ->where('branch_id', $manager->branch_id)
                ->where('created_by', $manager->id)
                ->with(['items.item', 'items.purchaseOrderItem.purchaseOrder', 'assignedTo'])
                ->withCount('items')
                ->firstOrFail();

            return $this->successResponse(
                new InventorySessionResource($session),
                'Inventory session retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching inventory session');
        }
    }

    /**
     * Delete inventory session (draft only)
     *
     * @group Daily Quick Inventory
     */
    public function deleteSession(string $id): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = auth()->user();

            $session = InventorySession::where('id', $id)
                ->where('branch_id', $manager->branch_id)
                ->where('created_by', $manager->id)
                ->where('status', \Modules\Inventory\Enums\InventorySessionStatus::DRAFT)
                ->firstOrFail();

            $session->delete();

            return $this->successResponse(
                null,
                'Inventory session deleted successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'deleting inventory session');
        }
    }
}
