<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Supplier\Http\Requests\Returns\ApproveReturnRequest;
use Modules\Supplier\Http\Requests\Returns\RejectReturnRequest;
use Modules\Supplier\Http\Requests\Returns\ProcessReturnRequest;
use Modules\Supplier\Services\ReturnManagementService;
use Modules\Purchase\Transformers\ReturnOrderResource;
use Modules\Supplier\Transformers\ReturnDetailResource;

class ReturnManagementController extends BaseController
{
    public function __construct(
        private readonly ReturnManagementService $returnService
    ) {}

    /**
     * Get return requests
     */
    public function getReturnRequests(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $filters = request()->only(['status', 'search']);
            $perPage = (int) request()->get('per_page', 15) ?: 15;

            $returns = $this->returnService->getReturnRequests($supplier, $filters, $perPage);

            // Ensure ResourceCollection never receives null (prevents "map on null" error)
            if ($returns === null) {
                $returns = new LengthAwarePaginator([], 0, $perPage, 1, ['path' => request()->url()]);
            }

            return $this->paginatedResponse(
                ReturnOrderResource::collection($returns),
                'Return requests retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching return requests');
        }
    }

    /**
     * Get return details
     */
    public function viewReturnDetails(string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $return = $this->returnService->getReturnDetails($id, $supplier);

            if (!$return) {
                return $this->notFoundResponse('Return request not found');
            }

            return $this->successResponse(
                new ReturnDetailResource($return),
                'Return details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching return details');
        }
    }

    /**
     * Approve return
     */
    public function approveReturn(ApproveReturnRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $return = ReturnOrder::findOrFail($id);

            $return = $this->returnService->approveReturn($return, $supplier, $request->validated());

            return $this->successResponse(
                new ReturnOrderResource($return),
                'Return approved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving return');
        }
    }

    /**
     * Reject return
     */
    public function rejectReturn(RejectReturnRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $return = ReturnOrder::findOrFail($id);

            $return = $this->returnService->rejectReturn($return, $supplier, $request->validated());

            return $this->successResponse(
                new ReturnOrderResource($return),
                'Return rejected successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting return');
        }
    }

    /**
     * Process return
     */
    public function processReturn(ProcessReturnRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $return = ReturnOrder::findOrFail($id);

            $return = $this->returnService->processReturn($return, $supplier, $request->validated());

            return $this->successResponse(
                new ReturnOrderResource($return),
                'Return processed successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'processing return');
        }
    }
}

