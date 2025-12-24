<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Http\Requests\Fulfillment\StartPreparationRequest;
use Modules\Supplier\Http\Requests\Fulfillment\UpdatePreparationRequest;
use Modules\Supplier\Http\Requests\Fulfillment\StartDeliveryRequest;
use Modules\Supplier\Http\Requests\Fulfillment\ReportDelayRequest;
use Modules\Supplier\Http\Requests\Fulfillment\CompleteDeliveryRequest;
use Modules\Supplier\Http\Requests\Fulfillment\SubmitInvoiceRequest;
use Modules\Supplier\Services\OrderFulfillmentService;
use Modules\Supplier\Transformers\OrderResource;
use Modules\Supplier\Transformers\InvoiceResource;

class OrderFulfillmentController extends BaseController
{
    public function __construct(
        private readonly OrderFulfillmentService $fulfillmentService
    ) {}

    /**
     * Start order preparation
     */
    public function startPreparation(StartPreparationRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $order = PurchaseOrder::findOrFail($id);

            $order = $this->fulfillmentService->startPreparation($order, $supplier, $request->validated());

            return $this->successResponse(
                new OrderResource($order),
                'Order preparation started successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'starting preparation');
        }
    }

    /**
     * Update preparation progress
     */
    public function updatePreparation(UpdatePreparationRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $order = PurchaseOrder::findOrFail($id);

            $order = $this->fulfillmentService->updatePreparation($order, $supplier, $request->validated());

            return $this->successResponse(
                new OrderResource($order),
                'Preparation progress updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating preparation');
        }
    }

    /**
     * Start delivery
     */
    public function startDelivery(StartDeliveryRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $order = PurchaseOrder::findOrFail($id);

            $order = $this->fulfillmentService->startDelivery($order, $supplier, $request->validated());

            return $this->successResponse(
                new OrderResource($order),
                'Delivery started successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'starting delivery');
        }
    }

    /**
     * Report delivery delay
     */
    public function reportDelay(ReportDelayRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $order = PurchaseOrder::findOrFail($id);

            $order = $this->fulfillmentService->reportDelay($order, $supplier, $request->validated());

            return $this->successResponse(
                new OrderResource($order),
                'Delay reported successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'reporting delay');
        }
    }

    /**
     * Complete delivery
     */
    public function completeDelivery(CompleteDeliveryRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $order = PurchaseOrder::findOrFail($id);

            $order = $this->fulfillmentService->completeDelivery($order, $supplier, $request->validated());

            return $this->successResponse(
                new OrderResource($order),
                'Delivery completed successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'completing delivery');
        }
    }

    /**
     * Submit invoice
     */
    public function submitInvoice(SubmitInvoiceRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $order = PurchaseOrder::findOrFail($id);

            $invoice = $this->fulfillmentService->submitInvoice($order, $supplier, $request->validated());

            return $this->successResponse(
                new InvoiceResource($invoice),
                'Invoice submitted successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'submitting invoice');
        }
    }
}

