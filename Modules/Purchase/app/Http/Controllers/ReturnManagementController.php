<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UnifiedTimelineResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Http\Requests\CreateReturnRequest;
use Modules\Purchase\Http\Requests\EscalateRequest;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Repositories\PurchaseOrderRepository;
use Modules\Purchase\Services\ReturnManagementService;
use Modules\Purchase\Transformers\ReturnOrderListResource;
use Modules\Purchase\Transformers\ReturnOrderResource;

class ReturnManagementController extends BaseController
{
    public function __construct(
        private readonly ReturnManagementService $returnService,
        private readonly PurchaseOrderRepository $purchaseOrderRepository
    ) {}

    /**
     * Get in-progress returns
     *
     * @group Return Management
     */
    public function inProgress(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->get('per_page', 15);
            $user = auth()->user();

            if ($user instanceof BrandOwner) {
                $returns = $this->returnService->getBrandOwnerInProgressReturns($perPage);
            } else {
                $returns = $this->returnService->getInProgressReturns($user->branch_id, $perPage);
            }

            return $this->paginatedResponse(
                ReturnOrderListResource::collection($returns),
                'In-progress returns retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching in-progress returns');
        }
    }

    /**
     * Get draft returns
     *
     * @group Return Management
     */
    public function drafts(Request $request): JsonResponse
    {
        try {
            $branchId = auth()->user()->branch_id;
            $returns = $this->returnService->getDraftReturns($branchId, $request->get('per_page', 15));

            return $this->paginatedResponse(
                ReturnOrderListResource::collection($returns),
                'Draft returns retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching draft returns');
        }
    }

    /**
     * Get completed returns
     *
     * @group Return Management
     */
    public function completed(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->get('per_page', 15);
            $user = auth()->user();

            if ($user instanceof BrandOwner) {
                $returns = $this->returnService->getBrandOwnerCompletedReturns($perPage);
            } else {
                $returns = $this->returnService->getCompletedReturns($user->branch_id, $perPage);
            }

            return $this->paginatedResponse(
                ReturnOrderListResource::collection($returns),
                'Completed returns retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching completed returns');
        }
    }

    /**
     * Create return order
     *
     * @group Return Management
     */
    public function store(CreateReturnRequest $request): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->purchaseOrderRepository->findByBranch($request->purchase_order_id, $userBranchId);

            if (! $order) {
                return $this->notFoundResponse('Purchase order not found');
            }

            if ($order->status !== \Modules\Purchase\Enums\OrderStatus::CLOSED) {
                return $this->errorResponse(
                    'Returns can only be created for closed orders. Current order status: '.$order->status->label(),
                    400
                );
            }

            $data = $request->validated();
            $data['created_by'] = auth()->id();

            $return = $this->returnService->createReturn($order, $data, $request);

            return $this->createdResponse(
                new ReturnOrderResource($return->load(['purchaseOrder', 'supplier', 'items', 'timelines'])),
                'Return order created successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating return order');
        }
    }

    /**
     * Update return order
     *
     * @group Return Management
     */
    public function update(CreateReturnRequest $request, string $id): JsonResponse
    {
        try {
            $return = ReturnOrder::find($id);

            if (! $return) {
                return $this->notFoundResponse('Return order not found');
            }

            if (! $return->is_draft) {
                return $this->errorResponse('Can only update draft returns', 400);
            }

            // Security: Verify user has access to this return's branch
            $userBranchId = auth()->user()->branch_id;
            if ($return->branch_id !== $userBranchId) {
                return $this->errorResponse('Unauthorized access to this return order', 403);
            }

            $data = $request->validated();
            $return = $this->returnService->updateReturn($return, $data, $request);

            return $this->successResponse(
                new ReturnOrderResource($return->load(['purchaseOrder', 'supplier', 'items', 'timelines'])),
                'Return order updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating return order');
        }
    }

    /**
     * Submit return order
     *
     * @group Return Management
     */
    public function submit(string $id): JsonResponse
    {
        try {
            $return = ReturnOrder::find($id);

            if (! $return) {
                return $this->notFoundResponse('Return order not found');
            }

            $success = $this->returnService->submitReturn($return);

            if (! $success) {
                return $this->errorResponse('Cannot submit return in current status', 400);
            }

            return $this->successResponse(
                new ReturnOrderResource($return->fresh(['items'])),
                'Return order submitted successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'submitting return order');
        }
    }

    /**
     * Accept rejection
     *
     * @group Return Management
     */
    public function acceptRejection(string $id): JsonResponse
    {
        try {
            $return = ReturnOrder::find($id);

            if (! $return) {
                return $this->notFoundResponse('Return order not found');
            }

            $this->returnService->acceptRejection($return);

            return $this->successResponse(
                new ReturnOrderResource($return->fresh()),
                'Rejection accepted successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'accepting rejection');
        }
    }

    /**
     * Escalate return
     *
     * @group Return Management
     */
    public function escalate(EscalateRequest $request, string $id): JsonResponse
    {
        try {
            $return = ReturnOrder::find($id);

            if (! $return) {
                return $this->notFoundResponse('Return order not found');
            }

            // Get brand owner ID from request, config, or use default
            // Priority: request > config > default
            $escalatedTo = $request->input('escalated_to');

            if (empty($escalatedTo)) {
                $escalatedTo = config('purchase.brand_owner_id') ?? 'brand-owner-uuid';
            }

            // Validate that we have a valid non-empty string
            if (empty($escalatedTo) || ! is_string($escalatedTo)) {
                return $this->errorResponse(
                    'Brand owner ID is required for escalation. Please configure BRAND_OWNER_ID in your .env file or provide escalated_to in the request.',
                    400
                );
            }

            $this->returnService->escalateReturn($return, $request->reason, $escalatedTo);

            return $this->successResponse(
                new ReturnOrderResource($return->fresh()),
                'Return escalated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'escalating return');
        }
    }

    /**
     * Save return as draft
     *
     * @group Return Management
     */
    public function saveDraft(CreateReturnRequest $request): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->purchaseOrderRepository->findByBranch($request->purchase_order_id, $userBranchId);

            if (! $order) {
                return $this->notFoundResponse('Purchase order not found');
            }

            if ($order->status !== \Modules\Purchase\Enums\OrderStatus::CLOSED) {
                return $this->errorResponse(
                    'Returns can only be created for closed orders. Current order status: '.$order->status->label(),
                    400
                );
            }

            $data = $request->validated();
            $data['created_by'] = auth()->id();

            $return = $this->returnService->saveDraft($order, $data, $request);

            return $this->createdResponse(
                new ReturnOrderResource($return->load(['purchaseOrder', 'supplier', 'items', 'timelines'])),
                'Return saved as draft successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'saving return draft');
        }
    }

    /**
     * Delete draft
     *
     * @group Return Management
     */
    public function deleteDraft(string $id): JsonResponse
    {
        try {
            $return = ReturnOrder::find($id);

            if (! $return) {
                return $this->notFoundResponse('Return order not found');
            }

            $success = $this->returnService->deleteDraft($return);

            if (! $success) {
                return $this->errorResponse('Cannot delete non-draft return', 400);
            }

            return $this->deletedResponse('Draft deleted successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'deleting draft');
        }
    }

    /**
     * Get return details
     *
     * @group Return Management
     */
    public function show(string $id): JsonResponse
    {
        try {
            $return = $this->returnService->getReturnDetails($id);

            if (! $return) {
                return $this->notFoundResponse('Return order not found');
            }

            $user = auth()->user();

            if ($user instanceof BrandOwner) {
                $allowed = [
                    ReturnStatus::ESCALATED,
                    ReturnStatus::ESCALATED_RESOLVED,
                    ReturnStatus::ESCALATED_REJECTED,
                ];
                if (! in_array($return->status, $allowed, true)) {
                    return $this->errorResponse('Unauthorized access to this return order', 403);
                }
            } else {
                if ($return->branch_id !== $user->branch_id) {
                    return $this->errorResponse('Unauthorized access to this return order', 403);
                }
            }

            return $this->successResponse(
                new ReturnOrderResource($return),
                'Return details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching return details');
        }
    }

    /**
     * Get return timeline
     *
     * @group Return Management
     */
    public function timeline(string $id): JsonResponse
    {
        try {
            $timeline = $this->returnService->getReturnTimeline($id);

            return $this->successResponse(
                UnifiedTimelineResource::collection($timeline),
                'Return timeline retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching return timeline');
        }
    }
}
