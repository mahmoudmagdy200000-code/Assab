<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Http\Controllers\Concerns\ResolvesInventoryActor;
use Modules\Inventory\Http\Requests\Monthly\AddFeedbackRequest;
use Modules\Inventory\Http\Requests\Monthly\CreateMonthlyInventoryRequest;
use Modules\Inventory\Http\Requests\Monthly\ExportMonthlyInventoryRequest;
use Modules\Inventory\Http\Requests\Monthly\ReturnToDraftRequest;
use Modules\Inventory\Http\Requests\Monthly\UpdateMonthlyInventoryProductRequest;
use Modules\Inventory\Models\MonthlyInventory;
use Modules\Inventory\Services\MonthlyInventoryExportService;
use Modules\Inventory\Services\MonthlyInventoryService;
use Modules\Inventory\Support\InventoryActor;
use Modules\Inventory\Transformers\MonthlyInventoryComparisonResource;
use Modules\Inventory\Transformers\MonthlyInventoryFeedbackResource;
use Modules\Inventory\Transformers\MonthlyInventoryListResource;
use Modules\Inventory\Transformers\MonthlyInventoryProductResource;
use Modules\Inventory\Transformers\MonthlyInventoryProgressResource;
use Modules\Inventory\Transformers\MonthlyInventoryReportResource;
use Modules\Inventory\Transformers\MonthlyInventoryResource;
use Modules\Inventory\Transformers\MonthlyInventorySetupInfoResource;
use Modules\Inventory\Transformers\MonthlyInventoryTimelineResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyInventoryController extends BaseController
{
    use ResolvesInventoryActor;

    public function __construct(
        private readonly MonthlyInventoryService $service,
        private readonly MonthlyInventoryExportService $exportService
    ) {}

    private function manager(): BranchManager
    {
        return $this->resolveInventoryActor()->requireManager();
    }

    /** Resolve inventory for current actor (manager or cashier). */
    private function findInventoryForActor(string $id, InventoryActor $actor, array $relations = []): ?MonthlyInventory
    {
        $branchId = $actor->getBranchId();
        if (!$branchId) {
            return null;
        }
        $createdBy = $actor->isManager() ? $actor->getActorId() : null;
        $staffCashierId = $actor->isCashier() ? $actor->getActorId() : null;
        return $this->service->findForBranchOrStaff($id, $branchId, $createdBy, $staffCashierId, $relations);
    }

    public function setupInfo(): JsonResponse
    {
        try {
            $manager = $this->manager();
            if (!$manager->branch_id) {
                return $this->errorResponse('Branch manager is not assigned to any branch', 400);
            }
            $info = $this->service->getSetupInfo($manager->branch_id);
            return $this->successResponse(
                new MonthlyInventorySetupInfoResource($info),
                'Setup info retrieved successfully'
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching setup info');
        }
    }

    public function staffOptions(): JsonResponse
    {
        try {
            $manager = $this->manager();
            if (!$manager->branch_id) {
                return $this->errorResponse('Branch manager is not assigned to any branch', 400);
            }
            $staff = $this->service->getStaffOptions($manager->branch_id);
            return $this->successResponse($staff, 'Staff options retrieved successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching staff options');
        }
    }

    public function lastTeam(): JsonResponse
    {
        try {
            $manager = $this->manager();
            if (!$manager->branch_id) {
                return $this->errorResponse('Branch manager is not assigned to any branch', 400);
            }
            $team = $this->service->getLastTeam($manager->branch_id);
            return $this->successResponse($team, 'Last team retrieved successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching last team');
        }
    }

    public function store(CreateMonthlyInventoryRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', MonthlyInventory::class);
            $manager = $this->manager();
            if (!$manager->branch_id) {
                return $this->errorResponse('Branch manager is not assigned to any branch', 400);
            }
            $inventory = $this->service->create($request->validated(), $manager);
            return $this->createdResponse(
                new MonthlyInventoryResource($inventory),
                'Monthly inventory created successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'creating monthly inventory');
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (!$branchId) {
                return $this->errorResponse('Not assigned to any branch', 400);
            }
            $status = $request->query('status');
            $filters = [
                'date_from' => $request->query('date_from'),
                'date_to' => $request->query('date_to'),
            ];
            $perPage = (int) $request->query('per_page', 15);
            if ($actor->isManager()) {
                $filters['created_by'] = $actor->getActorId();
                $paginator = $this->service->listByStatus($branchId, $status, $filters, $perPage);
            } else {
                $paginator = $this->service->listByStatusForStaff($branchId, $actor->getActorId(), $status, $filters, $perPage);
            }
            return $this->paginatedResponse(
                MonthlyInventoryListResource::collection($paginator),
                'Monthly inventories retrieved successfully'
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching monthly inventories');
        }
    }

    public function statusCounts(): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (!$branchId) {
                return $this->errorResponse('Not assigned to any branch', 400);
            }
            $counts = $actor->isManager()
                ? $this->service->getStatusCounts(['branch_id' => $branchId, 'created_by' => $actor->getActorId()])
                : $this->service->getStatusCountsForStaff($branchId, $actor->getActorId());
            return $this->successResponse($counts, 'Status counts retrieved successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching status counts');
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor, [
                'branch',
                'createdBy',
                'staff.user',
                'products',
                'timelines' => fn ($q) => $q->orderBy('occurred_at', 'asc'),
            ]);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $this->authorize('view', $inventory);
            return $this->successResponse(
                new MonthlyInventoryResource($inventory->loadCount('products')),
                'Monthly inventory retrieved successfully'
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching monthly inventory');
        }
    }

    public function products(string $id, Request $request): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $search = $request->query('search');
            $completedOnly = $request->boolean('completed_only')
                || $request->boolean('counted_only')
                || $request->boolean('inventoried_only');
            $products = $this->service->getProducts($id, $search, $completedOnly);
            return $this->successResponse(
                MonthlyInventoryProductResource::collection($products),
                'Products retrieved successfully'
            )->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching products');
        }
    }

    public function updateProduct(UpdateMonthlyInventoryProductRequest $request, string $id, string $productId): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $data = $request->validated();
            $product = $this->service->updateProductQuantity($id, $productId, $data, $actor->getActor());
            return $this->successResponse(
                new MonthlyInventoryProductResource($product),
                'Product quantity updated successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return $this->errorResponse($e->getMessage(), 403);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'updating product quantity');
        }
    }

    public function claimProduct(string $id, string $productId): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $product = $this->service->claimProduct($id, $productId, $actor->getActor());
            return $this->successResponse(new MonthlyInventoryProductResource($product), 'Product claimed successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 409);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'claiming product');
        }
    }

    public function releaseProduct(string $id, string $productId): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $product = $this->service->releaseProduct($id, $productId, $actor->getActor());
            return $this->successResponse(new MonthlyInventoryProductResource($product), 'Product released successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return $this->errorResponse($e->getMessage(), 403);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'releasing product');
        }
    }

    public function progress(string $id, Request $request): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $search = $request->query('search');
            $progress = $this->service->getProgress($id, $search);
            return $this->successResponse(
                new MonthlyInventoryProgressResource($progress),
                'Progress retrieved successfully'
            )->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching progress');
        }
    }

    public function saveProgress(Request $request, string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $moveToDraft = $request->boolean('move_to_draft');
            $updated = $this->service->saveProgress($id, $moveToDraft);
            return $this->successResponse(
                new MonthlyInventoryResource($updated),
                $moveToDraft ? 'Progress saved and moved to draft' : 'Progress saved successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'saving progress');
        }
    }

    public function review(string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $updated = $this->service->markForReview($id);
            return $this->successResponse(
                new MonthlyInventoryResource($updated),
                'Inventory marked for review successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'marking for review');
        }
    }

    public function submit(string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $this->authorize('submit', $inventory);
            $updated = $this->service->submitForApproval($id, $actor->getActor());
            return $this->successResponse(
                new MonthlyInventoryResource($updated),
                'Inventory submitted for approval successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'submitting for approval');
        }
    }

    public function approve(string $id): JsonResponse
    {
        try {
            $manager = $this->manager();
            $inventory = $this->service->findForBranch($id, $manager->branch_id);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $this->authorize('approve', $inventory);
            $updated = $this->service->approve($id);
            return $this->successResponse(
                new MonthlyInventoryResource($updated),
                'Inventory approved successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'approving inventory');
        }
    }

    public function returnToDraft(ReturnToDraftRequest $request, string $id): JsonResponse
    {
        try {
            $manager = $this->manager();
            $inventory = $this->service->findForBranch($id, $manager->branch_id);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $this->authorize('returnToDraft', $inventory);
            $validated = $request->validated();
            $updated = $this->service->returnToDraft($id, $validated['feedback'], $manager);
            return $this->successResponse(
                new MonthlyInventoryResource($updated),
                'Inventory returned to draft successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'returning to draft');
        }
    }

    public function report(string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $data = $this->service->getReport($id);
            return $this->successResponse(
                new MonthlyInventoryReportResource($data),
                'Report retrieved successfully'
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching report');
        }
    }

    public function export(ExportMonthlyInventoryRequest $request, string $id): JsonResponse|StreamedResponse
    {
        try {
            $manager = $this->manager();
            if (!$manager->branch_id) {
                return $this->errorResponse('Branch manager is not assigned to any branch', 400);
            }
            $inventory = $this->service->findForBranch($id, $manager->branch_id);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $format = $request->validated()['format'];
            $date = $inventory->inventory_date?->format('Y-m') ?? now()->format('Y-m');
            $filename = "monthly-inventory-{$date}-{$id}." . ($format === 'excel' ? 'csv' : 'pdf');

            if ($format === 'pdf') {
                $content = $this->exportService->generatePdf($id);
                return response()->streamDownload(
                    fn () => print($content),
                    $filename,
                    ['Content-Type' => 'application/pdf']
                );
            }

            $content = $this->exportService->generateCsv($id);
            return response()->streamDownload(
                fn () => print($content),
                $filename,
                ['Content-Type' => 'text/csv']
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'exporting report');
        }
    }

    public function comparison(Request $request): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $branchId = $actor->getBranchId();
            if (!$branchId) {
                return $this->errorResponse('Not assigned to any branch', 400);
            }
            $branchId = $request->query('branch_id', $branchId);
            $year = (int) $request->query('year', now()->year);
            $month = (int) $request->query('month', now()->month);
            $data = $this->service->getMonthlyComparison($branchId, $year, $month);
            return $this->successResponse(
                new MonthlyInventoryComparisonResource($data),
                'Comparison retrieved successfully'
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching comparison');
        }
    }

    public function timelines(string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $timelines = $this->service->getTimelines($id);
            return $this->successResponse(
                MonthlyInventoryTimelineResource::collection($timelines),
                'Timelines retrieved successfully'
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching timelines');
        }
    }

    public function getFeedback(string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $feedback = $this->service->getFeedback($id);
            return $this->successResponse(
                MonthlyInventoryFeedbackResource::collection($feedback),
                'Feedback retrieved successfully'
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching feedback');
        }
    }

    public function addFeedback(AddFeedbackRequest $request, string $id): JsonResponse
    {
        try {
            $actor = $this->resolveInventoryActor();
            $inventory = $this->findInventoryForActor($id, $actor);
            if (!$inventory) {
                return $this->notFoundResponse('Monthly inventory not found');
            }
            $feedback = $this->service->addFeedback($id, $request->validated()['message'], $actor->getActor());
            return $this->createdResponse(
                new MonthlyInventoryFeedbackResource($feedback),
                'Feedback added successfully'
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'adding feedback');
        }
    }
}
