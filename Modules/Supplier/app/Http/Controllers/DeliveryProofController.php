<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Http\Requests\DeliveryProof\CreateDeliveryProofRequest;
use Modules\Supplier\Services\DeliveryProofService;
use Modules\Supplier\Transformers\DeliveryProofResource;

class DeliveryProofController extends BaseController
{
    public function __construct(
        private readonly DeliveryProofService $deliveryProofService
    ) {}

    /**
     * Get delivery proof for order
     */
    public function show(string $orderId): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $order = PurchaseOrder::findOrFail($orderId);

            $proof = $this->deliveryProofService->getProof($order, $supplier);

            if (!$proof) {
                return $this->notFoundResponse('Delivery proof not found');
            }

            return $this->successResponse(
                new DeliveryProofResource($proof),
                'Delivery proof retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching delivery proof');
        }
    }

    /**
     * Update delivery proof
     */
    public function update(CreateDeliveryProofRequest $request, string $orderId): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $order = PurchaseOrder::findOrFail($orderId);

            $proof = $this->deliveryProofService->getProof($order, $supplier);

            if (!$proof) {
                return $this->notFoundResponse('Delivery proof not found');
            }

            $proof = $this->deliveryProofService->updateProof($proof, $supplier, $request->validated());

            return $this->successResponse(
                new DeliveryProofResource($proof),
                'Delivery proof updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating delivery proof');
        }
    }
}

