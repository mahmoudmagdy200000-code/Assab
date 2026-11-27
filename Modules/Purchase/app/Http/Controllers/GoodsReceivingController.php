<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Services\StreamUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Http\Requests\AddUnlistedItemRequest;
use Modules\Purchase\Http\Requests\CreateDeliveryNoteRequest;
use Modules\Purchase\Http\Requests\CreateInvoiceRequest;
use Modules\Purchase\Http\Requests\InspectItemRequest;
use Modules\Purchase\Http\Requests\ReceiveWithoutOrderRequest;
use Modules\Purchase\Http\Requests\StartReceivingRequest;
use Modules\Purchase\Http\Requests\SupplierResponseRequest;
use Modules\Purchase\Http\Requests\UpdateDeliveryDetailsRequest;
use Modules\Purchase\Http\Requests\VarianceActionRequest;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Repositories\PurchaseOrderRepository;
use Modules\Purchase\Services\GoodsReceiptService;
use Modules\Purchase\Services\PurchaseAuthorizationService;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Services\SupplierCommunicationService;
use Modules\Purchase\Services\VarianceService;
use Modules\Purchase\Transformers\GoodsReceiptResource;
use Modules\Purchase\Transformers\OrderTrackingResource;
use Modules\Purchase\Transformers\SupplierCommunicationResource;
use Modules\Purchase\Transformers\VarianceResource;

class GoodsReceivingController extends BaseController
{
    public function __construct(
        private readonly GoodsReceiptService $receiptService,
        private readonly PurchaseOrderService $orderService,
        private readonly VarianceService $varianceService,
        private readonly SupplierCommunicationService $communicationService,
        private readonly PurchaseOrderRepository $purchaseOrderRepository,
        private readonly StreamUploadService $streamUpload,
        private readonly PurchaseAuthorizationService $authService
    ) {}

    /**
     * Get in-progress receipts list
     *
     * @group Goods Receiving
     */
    public function inProgress(Request $request): JsonResponse
    {
        try {
            $branchId = auth()->user()->branch_id;
            $receipts = $this->receiptService->getInProgressReceipts($branchId, $request->get('per_page', 15));

            // Data is already transformed in service, pass paginator directly
            return $this->paginatedResponse(
                $receipts,
                'In-progress receipts retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching in-progress receipts');
        }
    }

    /**
     * Get orders ready for receiving
     *
     * @group Goods Receiving
     */
    public function ordersForReceiving(Request $request): JsonResponse
    {
        try {
            $filters = [
                'branch_id' => auth()->user()->branch_id,
                'type' => $request->get('type'),
            ];

            $orders = $this->orderService->getOrdersForReceiving($filters, $request->get('per_page', 15));

            return $this->paginatedResponse(
                $orders,
                'Orders for receiving retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching orders for receiving');
        }
    }

    /**
     * Start receiving an order
     *
     * @group Goods Receiving
     */
    public function startReceiving(StartReceivingRequest $request, string $orderId): JsonResponse
    {
        try {
            $user = auth()->user();
            if ($this->authService->resolveActingBranchIds($user, true) === []) {
                return $this->forbiddenResponse('Unauthorized for goods receiving');
            }

            $order = PurchaseOrder::with([
                'branch', 'fromBranch', 'toBranch', 'supplier', 'requestedBy', 'items.item', 'goodsReceipts', 'timelines',
            ])->find($orderId);

            if (! $order) {
                return $this->notFoundResponse('Order not found');
            }

            if (! $this->authService->canReceivePurchaseOrder($user, $order, true)) {
                return $this->forbiddenResponse('Unauthorized access to this purchase order');
            }

            if (! $order->can_receive) {
                return $this->errorResponse('Order cannot be received in current status', 400);
            }

            // Get validated data
            $validated = $request->validated();

            // Validate that all item_ids belong to this order before any file upload or DB mutation
            $orderItemIds = $order->items->pluck('id')->toArray();
            $requestItemIds = collect($validated['items'])->pluck('item_id')->toArray();

            if (count($requestItemIds) !== count(array_unique($requestItemIds))) {
                return $this->validationErrorResponse(['items' => ['Duplicate order item IDs are not allowed in a single receipt.']]);
            }

            // Also collect variance items if present
            if (isset($validated['variance']['items'])) {
                $varianceItemIds = collect($validated['variance']['items'])->pluck('item_id')->toArray();
                $requestItemIds = array_merge($requestItemIds, $varianceItemIds);
            }

            $invalidItems = array_diff($requestItemIds, $orderItemIds);
            if (! empty($invalidItems)) {
                return $this->errorResponse('Some items do not belong to this order', 400);
            }

            // Validate unlisted items supplier & catalog permissions before any file upload
            if (isset($validated['unlisted_items']) && is_array($validated['unlisted_items'])) {
                foreach ($validated['unlisted_items'] as $unlistedItem) {
                    if (! empty($unlistedItem['supplier_id'])
                        && ! $this->authService->canAccessVisibleSupplier($user, (string) $unlistedItem['supplier_id'])) {
                        return $this->forbiddenResponse('Unauthorized supplier for unlisted item');
                    }
                    if (! empty($unlistedItem['item_id'])) {
                        $this->authService->assertItemsAllowedForWrite(
                            (string) $order->branch_id,
                            [(string) $unlistedItem['item_id']],
                            ! empty($unlistedItem['supplier_id']) ? (string) $unlistedItem['supplier_id'] : null
                        );
                    }
                }
            }

            // Handle file uploads for photos
            $itemsData = $validated['items'];

            \Log::info('GoodsReceiving: startReceiving called', [
                'order_id' => $orderId,
                'items_count' => count($itemsData),
                'has_variance' => isset($validated['variance']),
                'has_unlisted' => isset($validated['unlisted_items']),
            ]);

            foreach ($itemsData as $index => $itemData) {
                if ($request->hasFile("items.{$index}.photo")) {
                    $file = $request->file("items.{$index}.photo");
                    $itemsData[$index]['photo'] = $this->streamUpload->storeFromUpload($file, 'receipts/items', $file->hashName(), 'public');
                }
            }

            // Prepare document type and data
            $documentType = $validated['document_type'] ?? null;
            $documentData = null;

            if ($documentType === 'invoice' && isset($validated['invoice_data'])) {
                $documentData = $validated['invoice_data'];
                // Handle invoice photo upload
                if ($request->hasFile('invoice_data.photo')) {
                    $file = $request->file('invoice_data.photo');
                    $documentData['photo'] = $this->streamUpload->storeFromUpload($file, 'invoices', $file->hashName(), 'public');
                }
            } elseif ($documentType === 'delivery_note' && isset($validated['delivery_note_data'])) {
                $documentData = $validated['delivery_note_data'];
                // Handle delivery note file upload
                if ($request->hasFile('delivery_note_data.file')) {
                    $documentData['file'] = $request->file('delivery_note_data.file');
                }
            }

            // Prepare variance data (single object for all items with variance)
            $varianceData = null;
            if (isset($validated['variance']) && is_array($validated['variance'])) {
                $varianceData = [
                    'action' => $validated['variance']['action'],
                    'note' => $validated['variance']['note'] ?? null,
                ];

                // Handle variance photo upload
                if ($request->hasFile('variance.photo')) {
                    $file = $request->file('variance.photo');
                    $varianceData['photo'] = $this->streamUpload->storeFromUpload($file, 'variances', $file->hashName(), 'public');
                }

                // Handle compensatory order items (directly in variance)
                if ($varianceData['action'] === 'compensatory_order' && isset($validated['variance']['items'])) {
                    $varianceData['items'] = $validated['variance']['items'];
                }

                // Handle deduct from invoice data
                if ($varianceData['action'] === 'deduct_from_invoice' && isset($validated['variance']['deduct_data'])) {
                    $varianceData['deduct_data'] = $validated['variance']['deduct_data'];
                }
            }

            // Prepare unlisted items (gifts from supplier)
            $unlistedItems = [];
            if (isset($validated['unlisted_items']) && is_array($validated['unlisted_items'])) {
                foreach ($validated['unlisted_items'] as $index => $unlistedItem) {
                    $itemData = $unlistedItem;

                    // Handle photo upload for unlisted item
                    if ($request->hasFile("unlisted_items.{$index}.photo")) {
                        $file = $request->file("unlisted_items.{$index}.photo");
                        $itemData['photo'] = $this->streamUpload->storeFromUpload($file, 'receipts/unlisted', $file->hashName(), 'public');
                    }

                    $unlistedItems[] = $itemData;
                }
            }

            $receipt = $this->receiptService->startReceiving(
                $order,
                auth()->id(),
                $itemsData,
                $documentType,
                $documentData,
                $varianceData,
                $unlistedItems
            );

            if (class_exists(\Modules\Admin\Services\PurchaseOrderBridgeService::class)) {
                app(\Modules\Admin\Services\PurchaseOrderBridgeService::class)->syncReceivingState($order, $receipt);
            }

            return $this->createdResponse(
                new GoodsReceiptResource($receipt),
                'Receiving started successfully'
            );
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'messageAr' => $e->messageAr],
            ], $e->status);
        } catch (\InvalidArgumentException $e) {
            return $this->validationErrorResponse(['error' => [$e->getMessage()]], $e->getMessage());
        } catch (\Exception $e) {
            return $this->handleException($e, 'starting receiving');
        }
    }

    /**
     * Update delivery details
     *
     * @group Goods Receiving
     */
    public function updateDeliveryDetails(UpdateDeliveryDetailsRequest $request, string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::where('branch_id', auth()->user()->branch_id)->find($id);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            $data = $request->validated();

            // Handle driver image upload
            if ($request->hasFile('driver_image')) {
                $file = $request->file('driver_image');
                $data['driver_image'] = $this->streamUpload->storeFromUpload($file, 'receipts/drivers', $file->hashName(), 'public');
            }

            $receipt = $this->receiptService->setDeliveryDetails($receipt, $data);

            return $this->successResponse(
                new GoodsReceiptResource($receipt),
                'Delivery details updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating delivery details');
        }
    }

    /**
     * Inspect an item
     *
     * @group Goods Receiving
     */
    public function inspectItem(InspectItemRequest $request, string $receiptId, string $itemId): JsonResponse
    {
        try {
            $item = GoodsReceiptItem::with('goodsReceipt')
                ->where('goods_receipt_id', $receiptId)
                ->where('id', $itemId)
                ->first();

            if (! $item) {
                return $this->notFoundResponse('Item not found');
            }

            if (! $item->goodsReceipt || ! $this->authService->canAccessGoodsReceipt(auth()->user(), $item->goodsReceipt)) {
                return $this->forbiddenResponse('Unauthorized access to this receipt');
            }

            $data = $request->validated();

            // Handle photo upload
            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $data['photo'] = $this->streamUpload->storeFromUpload($file, 'receipts/items', $file->hashName(), 'public');
            }

            $item = $this->receiptService->updateItemInspection($item, $data);

            return $this->successResponse(
                $item,
                'Item inspected successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'inspecting item');
        }
    }

    /**
     * Add unlisted item (gift)
     *
     * @group Goods Receiving
     */
    public function addUnlistedItem(AddUnlistedItemRequest $request, string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::where('branch_id', auth()->user()->branch_id)->find($id);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            $data = $request->validated();

            // Handle photo upload
            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $data['photo'] = $this->streamUpload->storeFromUpload($file, 'receipts/unlisted', $file->hashName(), 'public');
            }

            $item = $this->receiptService->addUnlistedItem($receipt, $data);

            return $this->createdResponse(
                $item,
                'Unlisted item added successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'adding unlisted item');
        }
    }

    /**
     * Set document type
     *
     * @group Goods Receiving
     */
    public function setDocumentType(Request $request, string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::where('branch_id', auth()->user()->branch_id)->find($id);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            $type = DocumentType::from($request->document_type);
            $this->receiptService->setDocumentType($receipt, $type);

            return $this->successResponse(
                new GoodsReceiptResource($receipt->fresh()),
                'Document type set successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'setting document type');
        }
    }

    /**
     * Create invoice
     *
     * @group Goods Receiving
     */
    public function createInvoice(CreateInvoiceRequest $request, string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::where('branch_id', auth()->user()->branch_id)->find($id);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            $data = $request->validated();
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = $this->streamUpload->storeFromUpload($file, 'invoices', $file->hashName(), 'public');
                $data['file_path'] = $path;
                $data['file_name'] = $file->getClientOriginalName();
                $data['file_type'] = $file->getClientMimeType();
                $data['file_size'] = $file->getSize();
            }

            $invoice = $this->receiptService->createInvoice($receipt, $data);

            if (class_exists(\Modules\Admin\Services\PurchaseOrderBridgeService::class) && $receipt->purchaseOrder) {
                app(\Modules\Admin\Services\PurchaseOrderBridgeService::class)->syncReceivingState(
                    $receipt->purchaseOrder,
                    $receipt->fresh(),
                    $invoice,
                );
            }

            return $this->createdResponse(
                $invoice,
                'Invoice created successfully'
            );
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'messageAr' => $e->messageAr],
            ], $e->status);
        } catch (\InvalidArgumentException $e) {
            return $this->validationErrorResponse(['error' => [$e->getMessage()]], $e->getMessage());
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating invoice');
        }
    }

    /**
     * Complete inspection
     *
     * @group Goods Receiving
     */
    public function completeInspection(string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::where('branch_id', auth()->user()->branch_id)->find($id);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            $receipt = $this->receiptService->completeInspection($receipt);

            return $this->successResponse(
                new GoodsReceiptResource($receipt),
                'Inspection completed successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'completing inspection');
        }
    }

    /**
     * Save receipt as draft
     *
     * @group Goods Receiving
     */
    public function saveDraft(string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::where('branch_id', auth()->user()->branch_id)->find($id);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            $receipt = $this->receiptService->saveDraft($receipt);

            return $this->successResponse(
                new GoodsReceiptResource($receipt),
                'Receipt saved as draft successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'saving draft');
        }
    }

    /**
     * Get draft receipts
     *
     * @group Goods Receiving
     */
    public function drafts(Request $request): JsonResponse
    {
        try {
            $branchId = auth()->user()->branch_id;
            $orderType = $request->get('type');
            $receipts = $this->receiptService->getDraftReceipts($branchId, $request->get('per_page', 15), $orderType);

            return $this->paginatedResponse(
                $receipts,
                'Draft receipts retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching draft receipts');
        }
    }

    /**
     * Get draft details
     *
     * According to requirements 3.1.2.4.4.2.1.3:
     * - View Goods Draft Via Internal Transfer from Another Branch
     * - Displays order details, items, and available actions (Continue Editing, Delete Draft)
     *
     * @group Goods Receiving
     */
    public function getDraftDetails(string $id): JsonResponse
    {
        try {
            $receipt = $this->receiptService->getDraftDetails($id);

            if (! $receipt) {
                return $this->notFoundResponse('Draft receipt not found');
            }

            if (\Illuminate\Support\Facades\Gate::forUser(auth()->user())->denies('view', $receipt)) {
                return $this->forbiddenResponse('Unauthorized access to this draft receipt');
            }

            // Ensure all required relationships are loaded for Internal Transfer
            if ($receipt->purchaseOrder && $receipt->purchaseOrder->order_type === OrderType::INTERNAL_TRANSFER) {
                $receipt->purchaseOrder->loadMissing(['fromBranch', 'requestedBy', 'items']);
            }

            return $this->successResponse(
                new GoodsReceiptResource($receipt),
                'Draft details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching draft details');
        }
    }

    /**
     * Delete draft
     *
     * @group Goods Receiving
     */
    public function deleteDraft(string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::where('branch_id', auth()->user()->branch_id)->find($id);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            $success = $this->receiptService->deleteDraft($receipt);

            if (! $success) {
                return $this->errorResponse('Cannot delete non-draft receipt', 400);
            }

            return $this->deletedResponse('Draft deleted successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'deleting draft');
        }
    }

    /**
     * Get missing goods receipts (with variances)
     *
     * @group Goods Receiving
     */
    public function missingGoods(Request $request): JsonResponse
    {
        try {
            $branchId = auth()->user()->branch_id;
            $orderType = $request->get('type');
            $receipts = $this->receiptService->getMissingGoodsReceipts($branchId, $request->get('per_page', 15), $orderType);

            return $this->paginatedResponse(
                $receipts,
                'Missing goods receipts retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching missing goods receipts');
        }
    }

    /**
     * Get missing goods details
     *
     * @group Goods Receiving
     */
    public function getMissingGoodsDetails(string $id): JsonResponse
    {
        try {
            $receipt = $this->receiptService->getMissingGoodsDetails($id);

            if (! $receipt) {
                return $this->notFoundResponse('Missing goods receipt not found');
            }

            if (\Illuminate\Support\Facades\Gate::forUser(auth()->user())->denies('view', $receipt)) {
                return $this->forbiddenResponse('Unauthorized access to this receipt');
            }

            $order = $receipt->purchaseOrder;
            $supplierInfo = $this->communicationService->getSupplierInfo(
                $order->supplier_id,
                $order->id
            );
            $contactMethods = $this->communicationService->getContactMethods($order->id);

            return $this->successResponse([
                'receipt' => new GoodsReceiptResource($receipt),
                'inspection_summary' => [
                    'order_number' => $order->order_number,
                    'branch_name' => $order->branch->name ?? null,
                    'branch_location' => $order->branch->location ?? null,
                    'order_type' => $order->order_type?->value,
                    'supplier_name' => $order->supplier?->name,
                    'total_amount' => (float) $receipt->received_amount,
                ],
                'list_of_items' => $receipt->items->map(function ($item) {
                    return [
                        'item_name' => $item->item_name,
                        'item_logo' => $item->item_logo_url,
                        'quantity_ordered' => (float) $item->quantity_ordered,
                        'quantity_received' => (float) $item->quantity_received,
                        'quality' => $item->quality_received?->value,
                        'price' => (float) $item->unit_price,
                        'total_price' => (float) $item->received_total,
                    ];
                }),
                'contact_methods' => $contactMethods,
                'timeline_tracking' => $receipt->timelines->map(function ($timeline) {
                    return [
                        'stage' => $timeline->title,
                        'actor_name' => $timeline->actor?->name ?? 'System',
                        'actor_image' => $timeline->actor?->image ?? null,
                        'date' => $timeline->occurred_at?->format('Y-m-d'),
                        'time' => $timeline->occurred_at?->format('H:i:s'),
                        'status' => $timeline->new_status ?? $timeline->old_status,
                    ];
                }),
                'supplier_information' => new SupplierCommunicationResource($supplierInfo),
            ], 'Missing goods details retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching missing goods details');
        }
    }

    /**
     * Get completed receipts
     *
     * @group Goods Receiving
     */
    public function completed(Request $request): JsonResponse
    {
        try {
            $branchId = auth()->user()->branch_id;
            $orderType = $request->get('type');
            $receipts = $this->receiptService->getCompletedReceipts($branchId, $request->get('per_page', 15), $orderType);

            return $this->paginatedResponse(
                $receipts,
                'Completed receipts retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching completed receipts');
        }
    }

    /**
     * Get complete goods details
     *
     * @group Goods Receiving
     */
    public function getCompleteGoodsDetails(string $id): JsonResponse
    {
        try {
            $receipt = $this->receiptService->getCompleteGoodsDetails($id);

            if (! $receipt) {
                return $this->notFoundResponse('Complete goods receipt not found');
            }

            if (\Illuminate\Support\Facades\Gate::forUser(auth()->user())->denies('view', $receipt)) {
                return $this->forbiddenResponse('Unauthorized access to this receipt');
            }

            $order = $receipt->purchaseOrder;
            $supplierInfo = $this->communicationService->getSupplierInfo(
                $order->supplier_id,
                $order->id
            );
            $contactMethods = $this->communicationService->getContactMethods($order->id);

            return $this->successResponse([
                'receipt' => new GoodsReceiptResource($receipt),
                'inspection_summary' => [
                    'order_number' => $order->order_number,
                    'branch_name' => $order->branch->name ?? null,
                    'branch_location' => $order->branch->location ?? null,
                    'order_type' => $order->order_type?->value,
                    'supplier_name' => $order->supplier?->name,
                    'total_amount' => (float) $receipt->received_amount,
                ],
                'list_of_items' => $receipt->items->map(function ($item) {
                    return [
                        'item_name' => $item->item_name,
                        'item_logo' => $item->item_logo_url,
                        'quantity_ordered' => (float) $item->quantity_ordered,
                        'quantity_received' => (float) $item->quantity_received,
                        'quality' => $item->quality_received?->value,
                        'price' => (float) $item->unit_price,
                        'total_price' => (float) $item->received_total,
                    ];
                }),
                'contact_methods' => $contactMethods,
                'timeline_tracking' => $receipt->timelines->map(function ($timeline) {
                    return [
                        'stage' => $timeline->title,
                        'actor_name' => $timeline->actor?->name ?? 'System',
                        'actor_image' => $timeline->actor?->image ?? null,
                        'date' => $timeline->occurred_at?->format('Y-m-d'),
                        'time' => $timeline->occurred_at?->format('H:i:s'),
                        'status' => $timeline->new_status ?? $timeline->old_status,
                    ];
                }),
                'supplier_information' => new SupplierCommunicationResource($supplierInfo),
            ], 'Complete goods details retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching complete goods details');
        }
    }

    /**
     * Handle variance action
     *
     * @group Goods Receiving
     */
    public function handleVariance(VarianceActionRequest $request, string $varianceId): JsonResponse
    {
        try {
            $variance = $this->varianceService->getVarianceDetails($varianceId);

            if (! $variance) {
                return $this->notFoundResponse('Variance not found');
            }

            if (! $this->authService->canAccessVariance(auth()->user(), $variance)) {
                return $this->forbiddenResponse('Unauthorized access to this variance');
            }

            $action = $request->action;

            match ($action) {
                'accept' => $this->varianceService->acceptVariance($variance),
                'compensatory_order' => $this->varianceService->createCompensatoryOrder($variance, $request->validated()),
                'deduct_from_invoice' => $this->varianceService->deductFromInvoice(
                    $variance,
                    $request->amount,
                    $request->reason,
                    $request->notes
                ),
            };

            return $this->successResponse(
                new VarianceResource($variance->fresh()),
                'Variance action processed successfully'
            );
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'messageAr' => $e->messageAr],
            ], $e->status);
        } catch (\InvalidArgumentException $e) {
            return $this->validationErrorResponse(['amount' => [$e->getMessage()]], $e->getMessage());
        } catch (\Exception $e) {
            return $this->handleException($e, 'handling variance');
        }
    }

    /**
     * Receive goods without prior order
     *
     * @group Goods Receiving
     */
    public function receiveWithoutOrder(ReceiveWithoutOrderRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $data = $request->validated();
            $data['branch_id'] = $user->branch_id;
            $data['received_by'] = auth()->id();

            if (! empty($data['supplier_id']) && ! $this->authService->canAccessVisibleSupplier($user, (string) $data['supplier_id'])) {
                return $this->forbiddenResponse('Unauthorized supplier for this branch');
            }
            $this->authService->assertItemsAllowedForWrite(
                (string) $data['branch_id'],
                collect($data['items'] ?? [])->pluck('item_id')->all(),
                ! empty($data['supplier_id']) ? (string) $data['supplier_id'] : null
            );

            $receipt = $this->receiptService->receiveWithoutOrder($data);

            return $this->createdResponse(
                new GoodsReceiptResource($receipt),
                'Goods received successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            return $this->handleException($e, 'receiving goods without order');
        }
    }

    /**
     * Get receipt details
     *
     * @group Goods Receiving
     */
    public function show(string $id): JsonResponse
    {
        try {
            $receipt = $this->receiptService->getReceiptDetails($id);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            if (\Illuminate\Support\Facades\Gate::forUser(auth()->user())->denies('view', $receipt)) {
                return $this->forbiddenResponse('Unauthorized access to this receipt');
            }

            return $this->successResponse(
                new GoodsReceiptResource($receipt),
                'Receipt details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching receipt details');
        }
    }

    /**
     * Get receipt summary
     *
     * @group Goods Receiving
     */
    public function getReceiptSummary(string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::find($id);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            if (\Illuminate\Support\Facades\Gate::forUser(auth()->user())->denies('view', $receipt)) {
                return $this->forbiddenResponse('Unauthorized access to this receipt');
            }

            $summary = $this->receiptService->getReceiptSummary($id);

            if (empty($summary)) {
                return $this->notFoundResponse('Receipt not found');
            }

            return $this->successResponse(
                $summary,
                'Receipt summary retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching receipt summary');
        }
    }

    /**
     * Get variance summary for receipt
     *
     * @group Goods Receiving
     */
    public function getVarianceSummary(string $receiptId): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::find($receiptId);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            if (\Illuminate\Support\Facades\Gate::forUser(auth()->user())->denies('view', $receipt)) {
                return $this->forbiddenResponse('Unauthorized access to this receipt');
            }

            $summary = $this->varianceService->getVarianceSummary($receiptId);

            return $this->successResponse(
                $summary,
                'Variance summary retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching variance summary');
        }
    }

    /**
     * Get order tracking
     *
     * @group Goods Receiving
     */
    public function getOrderTracking(string $orderId): JsonResponse
    {
        try {
            $order = $this->purchaseOrderRepository->findWithRelations($orderId, []);

            if (! $order) {
                return $this->notFoundResponse('Order not found');
            }

            if (\Illuminate\Support\Facades\Gate::forUser(auth()->user())->denies('view', $order)) {
                return $this->forbiddenResponse('Unauthorized access to this purchase order');
            }

            $tracking = $this->receiptService->getOrderTracking($orderId);

            return $this->successResponse(
                new OrderTrackingResource(['order_id' => $orderId, ...$tracking]),
                'Order tracking retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching order tracking');
        }
    }

    /**
     * Get inspection details by order ID
     *
     * @group Goods Receiving
     */
    public function getInspectionDetailsByOrderId(Request $request, string $orderId): JsonResponse
    {
        try {
            $order = PurchaseOrder::find($orderId);

            if (! $order) {
                return $this->notFoundResponse('Order or receipt not found');
            }

            if (\Illuminate\Support\Facades\Gate::forUser(auth()->user())->denies('view', $order)) {
                return $this->forbiddenResponse('Unauthorized access to this purchase order');
            }

            $details = $this->receiptService->getInspectionDetailsByOrderId($orderId, $request->all());

            if (! $details) {
                return $this->notFoundResponse('Order or receipt not found');
            }

            return $this->successResponse(
                $details,
                'Inspection details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching inspection details');
        }
    }

    /**
     * Create delivery note
     *
     * @group Goods Receiving
     */
    public function createDeliveryNote(CreateDeliveryNoteRequest $request, string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::where('branch_id', auth()->user()->branch_id)->find($id);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            $this->receiptService->createDeliveryNote($receipt, $request->validated());

            return $this->successResponse(
                new GoodsReceiptResource($receipt->fresh()),
                'Delivery note created successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating delivery note');
        }
    }

    /**
     * Create receipt without document
     *
     * @group Goods Receiving
     */
    public function createReceiptWithoutDocument(string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::where('branch_id', auth()->user()->branch_id)->find($id);

            if (! $receipt) {
                return $this->notFoundResponse('Receipt not found');
            }

            $this->receiptService->createReceiptWithoutDocument($receipt);

            return $this->successResponse(
                new GoodsReceiptResource($receipt->fresh()),
                'Receipt without document created successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating receipt without document');
        }
    }

    /**
     * Receive internal transfer order
     *
     * According to requirements 3.1.2.4.4.1.2.3:
     * - Receive order via Internal Transfer from Another Branch
     * - After receiving, the order is automatically moved to Purchase History page and complete order page
     *
     * @group Goods Receiving
     */
    public function receiveInternalTransfer(Request $request, string $orderId): JsonResponse
    {
        try {
            $user = auth()->user();
            if ($this->authService->resolveActingBranchIds($user, true) === []) {
                return $this->forbiddenResponse('Only the receiving branch manager can receive this internal transfer.');
            }

            $order = PurchaseOrder::with([
                'branch', 'fromBranch', 'toBranch', 'supplier', 'requestedBy', 'items.item', 'goodsReceipts', 'timelines',
            ])->find($orderId);

            if (! $order) {
                return $this->notFoundResponse('Order not found');
            }

            if ($order->order_type !== OrderType::INTERNAL_TRANSFER) {
                if (! $this->authService->canAccessPurchaseOrder($user, $order, true)) {
                    return $this->forbiddenResponse('Unauthorized access to this purchase order.');
                }

                return $this->errorResponse('This endpoint is only for internal transfer orders', 400);
            }

            if (! $this->authService->canReceiveInternalTransfer($user, $order, true)) {
                return $this->errorResponse('Only the receiving branch manager can receive this internal transfer.', 403);
            }

            // Validate order status - must be in a receivable status
            // For Internal Transfer: fully_approved, partial_approved, confirmed, partial_confirmation
            $receivableStatuses = [
                'fully_approved',
                'partial_approved',
                'confirmed',
                'partial_confirmation',
            ];

            if (! in_array($order->status?->value, $receivableStatuses)) {
                return $this->errorResponse(
                    'Order must be approved (fully or partially) or confirmed before receiving. Current status: '.($order->status?->value ?? 'unknown'),
                    400
                );
            }

            $deliveryDetails = $request->only(['driver_name', 'driver_contact', 'vehicle_number', 'arrival_time', 'delivery_address', 'items']);
            $deliveryDetails['idempotency_key'] = $request->input('idempotency_key') ?? $request->header('Idempotency-Key');

            $receipt = $this->receiptService->receiveInternalTransfer(
                $order,
                auth()->id(),
                $deliveryDetails
            );

            // Order status is automatically updated to CLOSED or VARIANCE in service
            // This moves the order to Purchase History and Complete Order pages

            return $this->createdResponse(
                new GoodsReceiptResource($receipt->load(['purchaseOrder.fromBranch', 'purchaseOrder.requestedBy'])),
                'Internal transfer received successfully.'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'receiving internal transfer');
        }
    }

    /**
     * Close open internal transfer variance (receiving branch manager ONLY).
     *
     * @group Goods Receiving
     */
    public function closeTransferVariance(Request $request, string $orderId): JsonResponse
    {
        try {
            $data = $request->validate([
                'resolution' => ['required', 'in:late_receipt,returned_to_sender,standing_variance'],
                'reason' => ['nullable', 'string', 'max:500'],
                'evidence_path' => ['nullable', 'string', 'max:500'],
                'idempotency_key' => ['nullable', 'string', 'max:120'],
            ]);

            $user = auth()->user();
            $userBranchId = $this->authService->resolvePrimaryBranchId($user, true);
            $order = PurchaseOrder::withoutGlobalScopes()->find($orderId);

            if (! $order) {
                return $this->notFoundResponse('Order not found');
            }

            if ($order->order_type !== OrderType::INTERNAL_TRANSFER) {
                if (! $this->authService->canAccessPurchaseOrder($user, $order, true)) {
                    return $this->forbiddenResponse('Unauthorized access to this purchase order.');
                }

                return $this->errorResponse('This endpoint is only for internal transfer orders', 400);
            }

            if (! $this->authService->canReceiveInternalTransfer($user, $order, true)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'FORBIDDEN_TRANSFER_CLOSURE',
                        'message' => 'Only the receiving branch manager can close internal transfer variance.',
                        'messageAr' => 'إغلاق فروق التحويل الداخلي مسموح لمدير الفرع المستلم فقط',
                    ],
                ], 403);
            }

            // Multi-branch actors: use the authorized receiving branch, not the first one.
            $userBranchId = (string) $order->branch_id;

            $result = $this->receiptService->closeTransferVariance(
                $order,
                (string) auth()->id(),
                $userBranchId ? (string) $userBranchId : null,
                $data['resolution'],
                $data['reason'] ?? null,
                $data['evidence_path'] ?? null,
                $data['idempotency_key'] ?? $request->header('Idempotency-Key'),
            );

            return $this->successResponse($result, 'Internal transfer variance closed successfully.');
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'messageAr' => $e->messageAr],
            ], $e->status);
        } catch (\Exception $e) {
            return $this->handleException($e, 'closing internal transfer variance');
        }
    }

    /**
     * Handle supplier response to variance
     *
     * @group Goods Receiving
     */
    public function handleSupplierResponse(SupplierResponseRequest $request, string $varianceId): JsonResponse
    {
        try {
            $variance = $this->varianceService->getVarianceDetails($varianceId);

            if (! $variance) {
                return $this->notFoundResponse('Variance not found');
            }

            if (! $this->authService->canRespondToVariance(auth()->user(), $variance)) {
                return $this->forbiddenResponse('Only the owning supplier can respond to this variance');
            }

            $action = $request->action;

            if ($action === 'approve') {
                $this->varianceService->supplierApprove(
                    $variance,
                    auth()->id(),
                    $request->response
                );
            } else {
                $this->varianceService->supplierReject(
                    $variance,
                    auth()->id(),
                    $request->reason
                );
            }

            return $this->successResponse(
                new VarianceResource($variance->fresh()),
                'Supplier response processed successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'handling supplier response');
        }
    }

    /**
     * Accept supplier rejection
     *
     * @group Goods Receiving
     */
    public function acceptRejection(string $varianceId): JsonResponse
    {
        try {
            $variance = $this->varianceService->getVarianceDetails($varianceId);

            if (! $variance) {
                return $this->notFoundResponse('Variance not found');
            }

            if (! $this->authService->canAccessVariance(auth()->user(), $variance)) {
                return $this->forbiddenResponse('Unauthorized access to this variance');
            }

            if ($variance->status !== 'supplier_rejected') {
                return $this->errorResponse('Variance is not in rejected status', 400);
            }

            $this->varianceService->acceptRejection($variance);

            return $this->successResponse(
                new VarianceResource($variance->fresh()),
                'Rejection accepted successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'accepting rejection');
        }
    }

    /**
     * Escalate rejection to Brand Owner
     *
     * @group Goods Receiving
     */
    public function escalateRejection(Request $request, string $varianceId): JsonResponse
    {
        try {
            $variance = $this->varianceService->getVarianceDetails($varianceId);

            if (! $variance) {
                return $this->notFoundResponse('Variance not found');
            }

            if (! $this->authService->canAccessVariance(auth()->user(), $variance)) {
                return $this->forbiddenResponse('Unauthorized access to this variance');
            }

            if ($variance->status !== 'supplier_rejected') {
                return $this->errorResponse('Variance is not in rejected status', 400);
            }

            $request->validate([
                'reason' => ['required', 'string', 'max:1000'],
                'escalated_to' => ['required', 'string'],
            ]);

            $this->varianceService->escalateVariance(
                $variance,
                $request->reason,
                $request->escalated_to
            );

            return $this->successResponse(
                new VarianceResource($variance->fresh()),
                'Rejection escalated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'escalating rejection');
        }
    }
}
