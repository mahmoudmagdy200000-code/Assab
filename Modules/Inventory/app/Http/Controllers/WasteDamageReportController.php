<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Http\Controllers\Concerns\ResolvesInventoryActor;
use Modules\Inventory\Http\Requests\WasteDamage\StoreWasteDamageReportItemRequest;
use Modules\Inventory\Http\Requests\WasteDamage\StoreWasteDamageReportRequest;
use Modules\Inventory\Http\Requests\WasteDamage\UpdateWasteDamageReportItemRequest;
use Modules\Inventory\Services\InventorySessionService;
use Modules\Inventory\Services\WasteDamageProductService;
use Modules\Inventory\Services\WasteDamageReportService;
use Modules\Inventory\Enums\WasteDamageReportStatus;
use Modules\Inventory\Transformers\WasteDamageReportItemResource;
use Modules\Inventory\Transformers\WasteDamageReportListResource;
use Modules\Inventory\Transformers\WasteDamageReportResource;

class WasteDamageReportController extends BaseController
{
    use ResolvesInventoryActor;

    private const BRANCH_NOT_ASSIGNED_MESSAGE = 'Branch manager is not assigned to any branch';

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
            $manager = $this->resolveInventoryActor()->requireManager();
            if (!$manager->branch_id) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $info = $this->productService->getAssignmentInfo(
                $manager->branch_id,
                $manager->name ?? null
            );

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
            $manager = $this->resolveInventoryActor()->requireManager();
            if (!$manager->branch_id) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $filters = $request->only(['search', 'category', 'subcategory', 'per_page']);
            $perPage = (int) ($filters['per_page'] ?? 50);
            unset($filters['per_page']);

            $products = $this->productService->getProductsFromClosedOrdersForBranch(
                $manager->branch_id,
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
            $manager = $this->resolveInventoryActor()->requireManager();
            if (!$manager->branch_id) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $cashiers = $this->sessionService->getAvailableCashiers($manager->branch_id);
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
     * Create draft report (optionally with multiple items in one request, like daily inventory).
     * Send items[].photo as image file (multipart); photo is stored and path saved.
     */
    public function store(StoreWasteDamageReportRequest $request): JsonResponse
    {
        try {
            $manager = $this->resolveInventoryActor()->requireManager();
            if (!$manager->branch_id) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $assignedToType = $request->validated('assigned_to_type', 'personal');
            $assignedToId = $request->validated('assigned_to_id');
            $items = $request->validated('items', []);

            if (empty($items)) {
                $report = $this->reportService->createReport($manager->branch_id, $manager->id, $assignedToType, $assignedToId);
            } else {
                $report = $this->reportService->createReport($manager->branch_id, $manager->id, $assignedToType, $assignedToId);
                $items = $this->storeItemPhotosForReport($request, $report->id, $items);
                $this->reportService->addItemsToReport($report, $items, $manager->id);
            }

            $report->loadMissing('assignedTo');

            return $this->createdResponse(
                new WasteDamageReportResource($report),
                'Waste & damage report created successfully'
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating waste & damage report');
        }
    }

    /**
     * Store uploaded photos for items (items.0.photo, items.1.photo, ...) and return items with photo_path set.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function storeItemPhotosForReport(Request $request, string $reportId, array $items): array
    {
        $basePath = sprintf('waste-damage/reports/%s', $reportId);

        foreach (array_keys($items) as $index) {
            $key = "items.{$index}.photo";
            if (!$request->hasFile($key)) {
                continue;
            }
            $path = $request->file($key)->store($basePath, 'public');
            $items[$index]['photo_path'] = $path;
        }

        return $items;
    }

    /**
     * List reports (branch-scoped; cashier sees only reports assigned to them).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (!$branchId) {
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
            if (!$branchId) {
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

            if (!$report) {
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
            if (!$branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $data = $request->validated();
            $photoPath = $this->storePhotoIfPresent($request, $id);
            if ($photoPath !== null) {
                $data['photo_path'] = $photoPath;
            }

            $assignedToId = $actor->isCashier() ? $actor->getActorId() : null;
            $item = $this->reportService->addItem($id, $branchId, $data, $actor->getActorId(), $assignedToId);

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
            if (!$branchId) {
                return $this->errorResponse(self::BRANCH_NOT_ASSIGNED_MESSAGE, 400);
            }

            $data = $request->validated();
            $photoPath = $this->storePhotoIfPresent($request, $id);
            if ($photoPath !== null) {
                $data['photo_path'] = $photoPath;
            }

            $assignedToId = $actor->isCashier() ? $actor->getActorId() : null;
            $item = $this->reportService->updateItem($id, $itemId, $branchId, $data, $actor->getActorId(), $assignedToId);

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
            if (!$branchId) {
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
            if (!$branchId) {
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
        if (!$request->hasFile('photo')) {
            return null;
        }

        return $request->file('photo')->store(
            sprintf('waste-damage/reports/%s', $reportId),
            'public'
        );
    }
}
