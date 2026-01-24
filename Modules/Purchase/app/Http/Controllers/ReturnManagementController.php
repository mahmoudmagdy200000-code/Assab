<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Http\Requests\CreateReturnRequest;
use Modules\Purchase\Http\Requests\EscalateRequest;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Services\ReturnManagementService;
use Modules\Purchase\Transformers\ReturnOrderListResource;
use Modules\Purchase\Transformers\ReturnOrderResource;
use Modules\Purchase\Transformers\TimelineResource;

class ReturnManagementController extends BaseController
{
    public function __construct(
        private readonly ReturnManagementService $returnService
    ) {}

    /**
     * Get in-progress returns
     *
     * @group Return Management
     */
    public function inProgress(Request $request): JsonResponse
    {
        try {
            $branchId = auth()->user()->branch_id;
            $returns = $this->returnService->getInProgressReturns($branchId, $request->get('per_page', 15));

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
            $branchId = auth()->user()->branch_id;
            $returns = $this->returnService->getCompletedReturns($branchId, $request->get('per_page', 15));

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
            // Security: Verify user has access to this order's branch
            $userBranchId = auth()->user()->branch_id;
            $order = PurchaseOrder::where('branch_id', $userBranchId)
                ->find($request->purchase_order_id);

            if (!$order) {
                return $this->notFoundResponse('Purchase order not found');
            }

            // Validate that order is CLOSED (required for returns)
            if ($order->status !== \Modules\Purchase\Enums\OrderStatus::CLOSED) {
                return $this->errorResponse(
                    'Returns can only be created for closed orders. Current order status: ' . $order->status->label(),
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

            if (!$return) {
                return $this->notFoundResponse('Return order not found');
            }

            if (!$return->is_draft) {
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

            if (!$return) {
                return $this->notFoundResponse('Return order not found');
            }

            $success = $this->returnService->submitReturn($return);

            if (!$success) {
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

            if (!$return) {
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

            if (!$return) {
                return $this->notFoundResponse('Return order not found');
            }

            // Get brand owner ID (would come from config or relationship)
            $escalatedTo = config('purchase.brand_owner_id', 'brand-owner-uuid');

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
            // Security: Verify user has access to this order's branch
            $userBranchId = auth()->user()->branch_id;
            $order = PurchaseOrder::where('branch_id', $userBranchId)
                ->find($request->purchase_order_id);

            if (!$order) {
                return $this->notFoundResponse('Purchase order not found');
            }

            // Validate that order is CLOSED (required for returns)
            if ($order->status !== \Modules\Purchase\Enums\OrderStatus::CLOSED) {
                return $this->errorResponse(
                    'Returns can only be created for closed orders. Current order status: ' . $order->status->label(),
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

            if (!$return) {
                return $this->notFoundResponse('Return order not found');
            }

            $success = $this->returnService->deleteDraft($return);

            if (!$success) {
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

            if (!$return) {
                return $this->notFoundResponse('Return order not found');
            }

            // Security: Verify user has access to this return's branch
            $userBranchId = auth()->user()->branch_id;
            if ($return->branch_id !== $userBranchId) {
                return $this->errorResponse('Unauthorized access to this return order', 403);
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
                TimelineResource::collection($timeline),
                'Return timeline retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching return timeline');
        }
    }
}

