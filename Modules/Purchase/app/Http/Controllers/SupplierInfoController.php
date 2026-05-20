<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Purchase\Services\SupplierInfoService;
use Modules\Purchase\Transformers\SupplierInfoResource;

class SupplierInfoController extends BaseController
{
    public function __construct(
        private readonly SupplierInfoService $supplierInfoService
    ) {}

    /**
     * Get supplier info by purchase order ID.
     * Verifies order belongs to user's branch.
     *
     * @group Supplier Info
     */
    public function byOrder(string $orderId): JsonResponse
    {
        $branchId = auth()->user()->branch_id;
        $supplier = $this->supplierInfoService->getSupplierForOrder($orderId, $branchId);

        if (! $supplier) {
            return $this->notFoundResponse('Order not found, or it has no supplier, or access denied for your branch.');
        }

        return $this->successResponse(
            new SupplierInfoResource($supplier),
            'Supplier info retrieved successfully'
        );
    }

    /**
     * Get supplier info by return order ID.
     * Verifies return belongs to user's branch.
     *
     * @group Supplier Info
     */
    public function byReturn(string $returnId): JsonResponse
    {
        $branchId = auth()->user()->branch_id;
        $supplier = $this->supplierInfoService->getSupplierForReturn($returnId, $branchId);

        if (! $supplier) {
            return $this->notFoundResponse('Return order not found, or it has no supplier, or access denied for your branch.');
        }

        return $this->successResponse(
            new SupplierInfoResource($supplier),
            'Supplier info retrieved successfully'
        );
    }
}
