<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Inventory\Http\Controllers\Concerns\ResolvesInventoryActor;
use Modules\Inventory\Http\Requests\AddInventoryItemRequest;
use Modules\Inventory\Http\Requests\ApproveInventorySessionRequest;
use Modules\Inventory\Http\Requests\CreateInventorySessionRequest;
use Modules\Inventory\Http\Requests\RejectInventorySessionRequest;
use Modules\Inventory\Http\Requests\UpdateInventoryItemRequest;
use Modules\Inventory\Http\Requests\UpdateInventorySessionRequest;
use Modules\Inventory\Enums\InventorySessionStatus;
use Modules\Inventory\Models\InventorySession;
use Modules\Inventory\Services\InventorySessionService;
use Modules\Inventory\Transformers\InventoryItemResource;
use Modules\Inventory\Transformers\InventorySessionResource;
use Modules\Inventory\Transformers\InventorySessionSummaryResource;
use Modules\Inventory\Transformers\InventorySessionTimelineResource;

class DailyQuickInventoryController extends BaseController
{
    use ResolvesInventoryActor;

    private const BRANCH_NOT_ASSIGNED_MESSAGE = 'Branch manager is not assigned to any branch';

    public function __construct(
        private readonly InventorySessionService $sessionService
    ) {}

    /** Scope session query by current actor (manager: branch; cashier: assigned to them). */
    private function sessionsQueryForActor(BranchManager|Cashier $actor): Builder
    {
        $query = InventorySession::where('branch_id', $actor->branch_id);
        if ($actor instanceof Cashier) {
            $query->where('assigned_to_type', 'staff')->where('assigned_to_id', $actor->id);
        }
        return $query;
    }

    /**
     * Daily inventory dashboard for Branch Manager.
     * Returns total number of items (from schedule) and daily items managed by account manager.
     */
    public function dashboard(): JsonResponse
    {
        try {
            $manager = $this->resolveInventoryActor()->requireManager();
            if (!$manager->branch_id) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $schedule = app(\Modules\Inventory\Services\DailyInventoryScheduleService::class)
                ->getForBranch($manager->branch_id);

            $dailyItemsCount = 0;
            $dailyItems = [];
            if ($schedule) {
                $schedule->load('scheduleItems.item');
                $dailyItems = $schedule->scheduleItems->map(fn($si) => [
                    'item_id' => $si->item_id,
                    'item_name' => $si->item?->name,
                    'unit' => $si->item?->unit,
                    'logo' => $si->item?->logo,
                ])->values()->toArray();
                $dailyItemsCount = $schedule->scheduleItems->count();
            }

            $todaySession = InventorySession::where('branch_id', $manager->branch_id)
                ->whereDate('inventory_date', now()->toDateString())
                ->first();
            $totalItemsAdded = $todaySession ? $todaySession->items()->count() : $dailyItemsCount;

            return $this->successResponse([
                'total_items_added' => $totalItemsAdded,
                'daily_items_count' => $dailyItemsCount,
                'daily_items_managed_by_account_manager' => $dailyItems,
            ], 'Dashboard retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching dashboard');
        }
    }

    /**
     * Get branch items for daily inventory (from branch purchase configuration).
     * Branch is taken from the authenticated Branch Manager. Optional: ?branch_id=uuid (must match manager's branch), ?include_all=1 (return all items, do not exclude those already in sessions).
     *
     * @group Daily Quick Inventory
     */
    public function getBranchItems(): JsonResponse
    {
        try {
            $manager = $this->resolveInventoryActor()->requireManager();
            if (! $manager->branch_id) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $branchId = request()->query('branch_id');
            if ($branchId !== null && $branchId !== '') {
                if ((string) $branchId !== (string) $manager->branch_id) {
                    return $this->errorResponse('Branch ID does not match your assigned branch.', 403);
                }
            } else {
                $branchId = $manager->branch_id;
            }

            $includeAll = filter_var(request()->query('include_all'), FILTER_VALIDATE_BOOLEAN);
            $items = $this->sessionService->getBranchItems($branchId, $includeAll);

            // -- TEMP DEBUG: include diagnosis info when result is empty --
            if ($items->isEmpty()) {
                $totalBranchItems = \Modules\Purchase\Models\BranchItem::where('branch_id', $branchId)->count();
                $itemsInActiveSessions = \Modules\Inventory\Models\InventoryItem::where('branch_id', $branchId)
                    ->whereHas('inventorySession', fn($q) => $q->whereIn('status', ['draft', 'pending', 'pending_your_action', 'pending_your_confirmation']))
                    ->distinct('item_id')
                    ->count('item_id');
                return $this->successResponse([], 'Branch items retrieved successfully', 200, [
                    '_debug' => [
                        'manager_id'              => $manager->id,
                        'manager_branch_id'        => $manager->branch_id,
                        'queried_branch_id'        => $branchId,
                        'total_branch_items_in_db' => $totalBranchItems,
                        'items_blocked_by_active_session' => $itemsInActiveSessions,
                        'hint' => $totalBranchItems === 0
                            ? 'Run: php artisan db:seed --class="Modules\\Inventory\\Database\\Seeders\\BranchDailyProductsSeeder" --force'
                            : ($itemsInActiveSessions === $totalBranchItems
                                ? 'All items are in active sessions. Close/approve sessions or call with ?include_all=1'
                                : 'Items exist but are filtered. Try ?include_all=1 to see all.'),
                    ],
                ]);
            }
            // -- END TEMP DEBUG --

            return $this->successResponse(
                $items,
                'Branch items retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching branch items');
        }
    }

    /**
     * Get closed order items for inventory (legacy; prefer getBranchItems for daily inventory).
     *
     * @group Daily Quick Inventory
     */
    public function getClosedOrderItems(): JsonResponse
    {
        try {
            $manager = $this->resolveInventoryActor()->requireManager();
            if (!$manager->branch_id) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
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
            $manager = $this->resolveInventoryActor()->requireManager();
            if (!$manager->branch_id) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
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
            $manager = $this->resolveInventoryActor()->requireManager();
            if (!$manager->branch_id) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
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
            $manager = $this->resolveInventoryActor()->requireManager();
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
            $manager = $this->resolveInventoryActor()->requireManager();
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
            $actor = $this->resolveInventoryActor();
            $item = $this->sessionService->updateItem($itemId, $request->validated(), $actor->getActor());

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
            $manager = $this->resolveInventoryActor()->requireManager();
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
            $actor = $this->resolveInventoryActor();
            $session = $this->sessionService->submitSession($id, $actor->getActor());

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
            $actor = $this->resolveInventoryActor();
            $summary = $this->sessionService->getSessionSummary($id, $actor->getActor());

            return $this->successResponse(
                new InventorySessionSummaryResource($summary),
                'Session summary retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching session summary');
        }
    }

    /**
     * Get all inventory sessions. Optional filter by status (e.g. ?status=draft).
     * Valid status values: draft, pending, approved, rejected, pending_your_action, pending_your_confirmation, completed.
     *
     * @group Daily Quick Inventory
     */
    public function getSessions(): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            if (!$actor->getBranchId()) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $statusFilter = request()->query('status');
            $validStatuses = array_map(fn(InventorySessionStatus $s) => $s->value, InventorySessionStatus::cases());
            $hasStatusFilter = $statusFilter !== null && $statusFilter !== '';

            if ($hasStatusFilter && !in_array($statusFilter, $validStatuses, true)) {
                return $this->errorResponse(
                    'Invalid status. Valid values: ' . implode(', ', $validStatuses),
                    422
                );
            }

            $sessions = $this->sessionsQueryForActor($actor->getActor())
                ->when($hasStatusFilter, fn($q) => $q->where('status', $statusFilter))
                ->with(['items.item', 'items.purchaseOrderItem.purchaseOrder', 'assignedTo', 'createdBy', 'branch'])
                ->withCount('items')
                ->orderBy('created_at', 'desc')
                ->paginate();

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
            $actor = $this->resolveInventoryActor();
            $session = $this->sessionsQueryForActor($actor->getActor())
                ->where('id', $id)
                ->with(['items.item', 'items.purchaseOrderItem.purchaseOrder', 'assignedTo', 'createdBy'])
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
     * Reject inventory session (Account Manager). Branch Manager can then edit and resubmit.
     */
    public function rejectSession(RejectInventorySessionRequest $request, string $id): JsonResponse
    {
        try {
            $session = $this->sessionService->rejectSession($id, $request->validated('comment'));

            return $this->successResponse(
                new InventorySessionResource($session->load(['items', 'assignedTo', 'createdBy'])),
                'Inventory session rejected successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting inventory session');
        }
    }

    /**
     * Resubmit inventory session after rejection (Branch Manager).
     */
    public function resubmitSession(string $id): JsonResponse
    {
        try {
            $manager = $this->resolveInventoryActor()->requireManager();
            $session = $this->sessionService->resubmitSession($id, $manager);

            return $this->successResponse(
                new InventorySessionResource($session),
                'Inventory session resubmitted successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'resubmitting inventory session');
        }
    }

    /**
     * Approve inventory session (Account Manager). Optionally pass sales per item; runs discrepancy calculation.
     */
    public function approveSession(ApproveInventorySessionRequest $request, string $id): JsonResponse
    {
        try {
            $session = $this->sessionService->approveSession(
                $id,
                $request->validated('sales', []),
                $request->validated('recorded_waste', [])
            );

            return $this->successResponse(
                new InventorySessionResource($session),
                'Inventory session approved successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving inventory session');
        }
    }

    /**
     * Get discrepancy report for a session (when status is Pending Your Action).
     */
    public function getDiscrepancyReport(string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $session = $this->sessionsQueryForActor($actor->getActor())
                ->where('id', $id)
                ->with(['discrepancies.inventoryItem.item', 'items.item'])
                ->firstOrFail();

            $allDiscrepancies = $session->discrepancies;
            $discrepancies = $allDiscrepancies->whereNotNull('discrepancy_type');
            $matchingCount = $allDiscrepancies->whereNull('discrepancy_type')->count();
            $totalValue = $discrepancies->sum('difference_value_sar');
            $shortageCount = $discrepancies->where('discrepancy_type', 'shortage')->count();
            $overCount = $discrepancies->where('discrepancy_type', 'over')->count();

            $productsRequiringClarification = $discrepancies->map(function ($d) {
                return [
                    'product_name' => $d->inventoryItem->item_name ?? $d->inventoryItem->item?->name,
                    'opening_balance' => (float) $d->opening_balance,
                    'purchases' => (float) $d->purchases,
                    'sales' => (float) $d->sales,
                    'recorded_waste' => (float) $d->recorded_waste,
                    'net_transfer_in' => (float) $d->net_transfer_in,
                    'net_transfer_out' => (float) $d->net_transfer_out,
                    'theoretically_expected' => (float) $d->theoretically_expected,
                    'actual_from_inventory' => (float) $d->actual,
                    'difference_quantity' => (float) $d->difference_quantity,
                    'difference_value_sar' => (float) ($d->difference_value_sar ?? 0),
                    'discrepancy_type' => $d->discrepancy_type,
                ];
            });

            return $this->successResponse([
                'session_id' => $session->id,
                'status' => $session->status->value,
                'status_label' => $session->status_label,
                'summary' => [
                    'products_with_discrepancies' => $discrepancies->count(),
                    'matching_products' => $matchingCount,
                    'total_products' => $allDiscrepancies->count(),
                    'total_discrepancy_value_sar' => round($totalValue, 2),
                    'discrepancy_types' => [
                        'shortage' => $shortageCount,
                        'over' => $overCount,
                    ],
                ],
                'products_requiring_clarification' => $productsRequiringClarification,
            ], 'Discrepancy report retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching discrepancy report');
        }
    }

    /**
     * Mark discrepancy report as reviewed (Branch Manager). Logs timeline event.
     */
    public function markDiscrepancyReviewed(string $id): JsonResponse
    {
        try {
            $manager = $this->resolveInventoryActor()->requireManager();
            $session = $this->sessionService->markDiscrepancyReviewed($id, $manager);

            return $this->successResponse(
                new InventorySessionResource($session),
                'Discrepancy report marked as reviewed'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'marking discrepancy as reviewed');
        }
    }

    /**
     * Last 5 recorded quantities for a product in this branch (quick suggestions when entering quantity).
     */
    public function getLastQuantities(string $itemId): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (!$branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }
            $quantities = $this->sessionService->getLastQuantitiesForProduct($branchId, $itemId);
            return $this->successResponse(['quantities' => $quantities], 'Last quantities retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching last quantities');
        }
    }

    /**
     * Start a daily inventory session (Start Myself or Approve Assignment). Sets start_time and inventory_date.
     */
    public function startSession(string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $session = $this->sessionService->startSession($id, $actor->getActor());
            return $this->successResponse(
                new InventorySessionResource($session),
                'Session started successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'starting session');
        }
    }

    /**
     * Get timeline for an inventory session (Details tab - Timelines).
     */
    public function getTimelines(string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $session = $this->sessionsQueryForActor($actor->getActor())->where('id', $id)->firstOrFail();

            $timelines = $session->timelines()->orderBy('occurred_at', 'asc')->get();

            return $this->successResponse(
                InventorySessionTimelineResource::collection($timelines),
                'Timelines retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching timelines');
        }
    }

    /**
     * Delete inventory session (draft only). Branch Manager only.
     *
     * @group Daily Quick Inventory
     */
    public function deleteSession(string $id): JsonResponse
    {
        try {
            $manager = $this->resolveInventoryActor()->requireManager();

            $session = InventorySession::where('id', $id)
                ->where('branch_id', $manager->branch_id)
                ->where('status', \Modules\Inventory\Enums\InventorySessionStatus::DRAFT)
                ->where(function ($q) use ($manager) {
                    $q->whereNull('created_by')->orWhere('created_by', $manager->id);
                })
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
