<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Enums\WasteDamageReportStatus;
use Modules\Inventory\Http\Controllers\Concerns\ResolvesInventoryActor;
use Modules\Inventory\Http\Requests\WasteDamage\StoreWasteDamageReportItemRequest;
use Modules\Inventory\Http\Requests\WasteDamage\StoreWasteDamageReportRequest;
use Modules\Inventory\Http\Requests\WasteDamage\UpdateWasteDamageReportItemRequest;
use Modules\Inventory\Models\WasteDamageReport;
use Modules\Inventory\Services\InventorySessionService;
use Modules\Inventory\Services\WasteDamageProductService;
use Modules\Inventory\Services\WasteDamageReportService;
use Modules\Inventory\Transformers\WasteDamageReportItemResource;
use Modules\Inventory\Transformers\WasteDamageReportListResource;
use Modules\Inventory\Transformers\WasteDamageReportResource;

class WasteDamageReportController extends BaseController
{
    use ResolvesInventoryActor;

    private const BRANCH_NOT_ASSIGNED_MESSAGE = 'User is not assigned to any branch';

    public function __construct(
        private readonly WasteDamageReportService $reportService,
        private readonly WasteDamageProductService $productService,
        private readonly InventorySessionService $sessionService
    ) {}

    /**
     * Assignment context (branch metadata) for collapsible section.
     */
    public function assignmentInfo(): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (! $branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $creatorName = $actor->isManager()
                ? $actor->getManager()->name
                : $actor->getCashier()->name;

            $info = $this->productService->getAssignmentInfo($branchId, $creatorName);

            return $this->successResponse($info, 'Assignment info retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching assignment info');
        }
    }

    /**
     * Products from closed orders with Product Information (search/filter).
     */
    public function productsFromClosedOrders(Request $request): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (! $branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $filters = $request->only(['search', 'category', 'subcategory', 'per_page']);
            $perPage = (int) ($filters['per_page'] ?? 50);
            unset($filters['per_page']);

            $products = $this->productService->getProductsFromClosedOrdersForBranch(
                $branchId,
                $filters
            );

            if ($perPage > 0 && $products->count() > $perPage) {
                $products = $products->take($perPage)->values();
            }

            return $this->successResponse($products->values()->all(), 'Products retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching products from closed orders');
        }
    }

    /**
     * Branch employees (cashiers) for responsible-employee assignment.
     */
    public function employees(): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (! $branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $cashiers = $this->sessionService->getAvailableCashiers($branchId);
            $cashiers = $cashiers->load('branch:id,name');

            $data = $cashiers->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email ?? null,
                'phone' => $c->phone ?? null,
                'image' => $c->image ?? null,
                'branch_id' => $c->branch_id,
                'branch_name' => $c->branch?->name ?? null,
            ])->values()->all();

            return $this->successResponse($data, 'Employees retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching employees');
        }
    }

    /**
     * Create reports from a multi-item payload.
     * Each item produces its OWN independent report (1 item per report).
     * Shared request data (branch, actor, assignment) is duplicated across all reports.
     * Each report+item pair is committed in its own DB transaction, so a failure on one
     * item does not roll back previously-saved reports.
     * Send items[].photo as image file (multipart); photo is stored and path saved.
     */
    public function store(StoreWasteDamageReportRequest $request): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (! $branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $createdById = $actor->getActorId();
            $createdByType = $actor->getActor()->getMorphClass();
            $assignedToType = $request->validated('assigned_to_type', 'personal');
            $assignedToId = $request->validated('assigned_to_id');
            $items = $request->validated('items', []);
            $actorBranchManagerId = $actor->isManager() ? $createdById : null;
            $actorCashierId = $actor->isCashier() ? $createdById : null;

            // Cashier: reuse existing active report assigned to them by branch manager (no duplicate report).
            if ($actor->isCashier()) {
                $existingAssigned = WasteDamageReport::where('branch_id', $branchId)
                    ->where('assigned_to_type', 'staff')
                    ->where('assigned_to_id', $createdById)
                    ->whereIn('status', [
                        WasteDamageReportStatus::PENDING,
                        WasteDamageReportStatus::DRAFT,
                    ])
                    ->whereNull('submitted_at')
                    ->orderByDesc('created_at')
                    ->first();

                if ($existingAssigned) {
                    if (! empty($items)) {
                        foreach ($items as $index => $itemData) {
                            if ($request->hasFile("items.{$index}.photo")) {
                                $itemData['photo_path'] = $request->file("items.{$index}.photo")->store(
                                    sprintf('waste-damage/reports/%s', $existingAssigned->id),
                                    'public'
                                );
                            }

                            $this->reportService->addItemsToReport(
                                $existingAssigned,
                                [$itemData],
                                $actorBranchManagerId,
                                $actorCashierId,
                            );
                        }
                    }

                    $existingAssigned->loadMissing([
                        'assignedTo',
                        'items.item',
                        'items.responsibleEmployees.cashier.branch',
                        'items.responsibleEmployees.branchManager',
                    ]);

                    return $this->successResponse(
                        new WasteDamageReportResource($existingAssigned),
                        'Items added to assigned waste & damage report successfully'
                    );
                }
            }

            // No items: create a single empty report.
            if (empty($items)) {
                $report = $this->reportService->createReport(
                    $branchId,
                    $createdById,
                    $assignedToType,
                    $assignedToId,
                    $createdByType
                );
                $report->loadMissing('assignedTo');

                return $this->createdResponse(
                    new WasteDamageReportResource($report),
                    'Waste & damage report created successfully'
                );
            }

            // One report per item. Each pair in its own transaction.
            $createdReports = [];
            foreach ($items as $index => $itemData) {
                $report = \Illuminate\Support\Facades\DB::transaction(function () use (
                    $branchId,
                    $createdById,
                    $assignedToType,
                    $assignedToId,
                    $createdByType,
                    $itemData,
                    $index,
                    $request,
                    $actorBranchManagerId,
                    $actorCashierId
                ) {
                    $report = $this->reportService->createReport(
                        $branchId,
                        $createdById,
                        $assignedToType,
                        $assignedToId,
                        $createdByType
                    );

                    if ($request->hasFile("items.{$index}.photo")) {
                        $itemData['photo_path'] = $request->file("items.{$index}.photo")->store(
                            sprintf('waste-damage/reports/%s', $report->id),
                            'public'
                        );
                    }

                    $this->reportService->addItemsToReport(
                        $report,
                        [$itemData],
                        $actorBranchManagerId,
                        $actorCashierId,
                    );

                    return $report;
                });

                $report->loadMissing([
                    'assignedTo',
                    'items.item',
                    'items.responsibleEmployees.cashier.branch',
                    'items.responsibleEmployees.branchManager',
                ]);
                $createdReports[] = $report;
            }

            return $this->createdResponse(
                WasteDamageReportResource::collection(collect($createdReports)),
                count($createdReports).' waste & damage report(s) created successfully'
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating waste & damage report');
        }
    }

    /**
     * List reports (branch-scoped; cashier sees only reports assigned to them).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (! $branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $perPage = (int) $request->get('per_page', 15);
            $status = $request->get('status');
            if ($status !== null && $status !== '' && WasteDamageReportStatus::tryFrom($status) === null) {
                return $this->errorResponse('Invalid status. Allowed: draft, pending, pending_your_confirmation, completed.', 422);
            }
            $assignedToId = $actor->isCashier() ? $actor->getActorId() : null;
            $reports = $this->reportService->listReportsByBranch(
                $branchId,
                $perPage > 0 ? $perPage : 15,
                $status ? (string) $status : null,
                $assignedToId
            );
            $reports->loadCount('items');
            $reports->load([
                'branch',
                'createdBy',
                'assignedTo',
                'items.item',
                'items.purchaseOrderItem',
                'items.responsibleEmployees.cashier.branch',
                'items.responsibleEmployees.branchManager',
            ]);

            return $this->paginatedResponse(
                WasteDamageReportListResource::collection($reports),
                'Reports retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'listing waste & damage reports');
        }
    }

    /**
     * Report detail + items (summary). Cashier sees only reports assigned to them.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (! $branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $assignedToId = $actor->isCashier() ? $actor->getActorId() : null;
            $report = $this->reportService->findReportForBranch($id, $branchId, [
                'createdBy',
                'assignedTo',
                'items.item',
                'items.purchaseOrderItem',
                'items.responsibleEmployees.cashier.branch',
                'items.responsibleEmployees.branchManager',
                'timelines',
            ], $assignedToId);

            if (! $report) {
                return $this->notFoundResponse('Report not found');
            }

            return $this->successResponse(
                new WasteDamageReportResource($report),
                'Report retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching report');
        }
    }

    /**
     * Add product line to report. Cashier may add to reports assigned to them.
     */
    public function storeItem(StoreWasteDamageReportItemRequest $request, string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (! $branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $data = $request->validated();
            $photoPath = $this->storePhotoIfPresent($request, $id);
            if ($photoPath !== null) {
                $data['photo_path'] = $photoPath;
            }

            $assignedToId = $actor->isCashier() ? $actor->getActorId() : null;
            $item = $this->reportService->addItem(
                $id,
                $branchId,
                $data,
                $actor->isManager() ? $actor->getActorId() : null,
                $actor->isCashier() ? $actor->getActorId() : null,
                $assignedToId,
            );

            return $this->createdResponse(
                new WasteDamageReportItemResource($item),
                'Item added to report successfully'
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->handleException($e, 'adding item to report');
        }
    }

    /**
     * Update report item. Cashier may update reports assigned to them.
     */
    public function updateItem(UpdateWasteDamageReportItemRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (! $branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $data = $request->validated();
            $photoPath = $this->storePhotoIfPresent($request, $id);
            if ($photoPath !== null) {
                $data['photo_path'] = $photoPath;
            }

            $assignedToId = $actor->isCashier() ? $actor->getActorId() : null;
            $item = $this->reportService->updateItem(
                $id,
                $itemId,
                $branchId,
                $data,
                $actor->isManager() ? $actor->getActorId() : null,
                $actor->isCashier() ? $actor->getActorId() : null,
                $assignedToId,
            );

            return $this->successResponse(
                new WasteDamageReportItemResource($item),
                'Report item updated successfully'
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating report item');
        }
    }

    /**
     * Remove report item. Cashier may remove from reports assigned to them.
     */
    public function deleteItem(string $id, string $itemId): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (! $branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $assignedToId = $actor->isCashier() ? $actor->getActorId() : null;
            $this->reportService->deleteItem($id, $itemId, $branchId, $assignedToId);

            return $this->successResponse(null, 'Report item removed successfully');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->handleException($e, 'removing report item');
        }
    }

    /**
     * Submit report. Cashier may submit reports assigned to them.
     */
    public function submit(string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (! $branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $assignedToId = $actor->isCashier() ? $actor->getActorId() : null;
            $report = $this->reportService->submitReport($id, $branchId, $assignedToId);
            $report->load(['items.item', 'items.responsibleEmployees.cashier.branch']);

            return $this->successResponse(
                new WasteDamageReportResource($report),
                'Report submitted successfully'
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->handleException($e, 'submitting report');
        }
    }

    /**
     * Store uploaded photo and return storage path, or null if no photo.
     */
    private function storePhotoIfPresent(Request $request, string $reportId): ?string
    {
        if (! $request->hasFile('photo')) {
            return null;
        }

        return $request->file('photo')->store(
            sprintf('waste-damage/reports/%s', $reportId),
            'public'
        );
    }
}
