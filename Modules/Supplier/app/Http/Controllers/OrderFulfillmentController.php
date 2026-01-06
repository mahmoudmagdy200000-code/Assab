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
    public function startPreparation(StartPreparationRequest $request): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $data = $request->validated();
            
            $order = PurchaseOrder::findOrFail($data['order_id']);

            // Handle items with files
            // In formdata, files come as items[0][file], items[1][file], etc.
            $items = [];
            $allFiles = $request->allFiles();
            
            // Get items from request
            $itemsInput = $request->input('items', []);
            if (is_array($itemsInput)) {
                foreach ($itemsInput as $index => $itemData) {
                    if (is_array($itemData) && isset($itemData['id'])) {
                        $item = [
                            'id' => $itemData['id'],
                            'file' => null,
                        ];
                        
                        // Try to get file using different formats
                        $fileKey1 = "items.{$index}.file";
                        $fileKey2 = "items[{$index}][file]";
                        
                        if (isset($allFiles[$fileKey1])) {
                            $item['file'] = $allFiles[$fileKey1];
                        } elseif (isset($allFiles[$fileKey2])) {
                            $item['file'] = $allFiles[$fileKey2];
                        } elseif ($request->hasFile($fileKey1)) {
                            $item['file'] = $request->file($fileKey1);
                        } elseif ($request->hasFile($fileKey2)) {
                            $item['file'] = $request->file($fileKey2);
                        }
                        
                        $items[] = $item;
                    }
                }
            }
            $data['items'] = $items;

            $order = $this->fulfillmentService->startPreparation($order, $supplier, $data);

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

            $data = $request->validated();
            if ($request->hasFile('driver_photo')) {
                $data['driver_photo'] = $request->file('driver_photo');
            }
            // Add notes if provided
            if ($request->has('notes')) {
                $data['notes'] = $request->input('notes');
            }

            $order = $this->fulfillmentService->startDelivery($order, $supplier, $data);

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

            $data = $request->validated();
            if ($request->hasFile('photo')) {
                $data['photo'] = $request->file('photo');
            }

            $order = $this->fulfillmentService->reportDelay($order, $supplier, $data);

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

            $data = $request->validated();
            if ($request->hasFile('recipient_signature')) {
                $data['recipient_signature'] = $request->file('recipient_signature');
            }
            // Handle delivery_photos array
            if ($request->hasFile('delivery_photos')) {
                $photos = $request->file('delivery_photos');
                // Ensure it's an array
                if (!is_array($photos)) {
                    $photos = [$photos];
                }
                $data['delivery_photos'] = $photos;
            }

            $order = $this->fulfillmentService->completeDelivery($order, $supplier, $data);

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

