<?php

namespace Modules\Purchase\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\VarianceType;
use Modules\Purchase\Models\CompensatoryOrder;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\OrderDocument;
use Modules\Purchase\Models\PurchaseInvoice;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseVariance;
use Modules\Purchase\Transformers\FileResource;
use Modules\Purchase\Transformers\GoodsReceiptListResource;

class GoodsReceiptService
{
    public function __construct(
        private readonly TimelineService $timelineService,
        private readonly VarianceService $varianceService,
        private readonly CalculationService $calculationService,
        private readonly PurchaseOrderService $orderService,
        private readonly OrderTrackingService $trackingService
    ) {}

    /**
     * Get receipts in progress
     */
    public function getInProgressReceipts(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        $paginator = GoodsReceipt::with(['purchaseOrder:id,order_type'])
            ->withCount('items as items_count')
            ->byBranch($branchId)
            ->whereIn('status', ['draft', 'in_progress'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Use Resource class through paginator
        return $paginator->through(fn($receipt) => new GoodsReceiptListResource($receipt));
    }

    /**
     * Get draft receipts
     */
    public function getDraftReceipts(string $branchId, int $perPage = 15, ?string $orderType = null): LengthAwarePaginator
    {
        $query = GoodsReceipt::with(['purchaseOrder:id,order_type', 'purchaseOrder.items', 'purchaseOrder.supplier'])
            ->withCount('items as items_count')
            ->byBranch($branchId)
            ->draft();

        if ($orderType) {
            $query->whereHas('purchaseOrder', function ($q) use ($orderType) {
                $q->where('order_type', $orderType);
            });
        }

        $paginator = $query->orderBy('updated_at', 'desc')->paginate($perPage);

        // Use Resource class through paginator
        return $paginator->through(fn($receipt) => new GoodsReceiptListResource($receipt));
    }

    /**
     * Get receipts with missing goods (variances)
     */
    public function getMissingGoodsReceipts(string $branchId, int $perPage = 15, ?string $orderType = null): LengthAwarePaginator
    {
        $query = GoodsReceipt::with(['purchaseOrder:id,order_type', 'purchaseOrder.supplier', 'variances', 'items'])
            ->withCount('items as items_count')
            ->byBranch($branchId)
            ->withVariances();

        if ($orderType) {
            $query->whereHas('purchaseOrder', function ($q) use ($orderType) {
                $q->where('order_type', $orderType);
            });
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Use Resource class through paginator
        return $paginator->through(fn($receipt) => new GoodsReceiptListResource($receipt));
    }

    /**
     * Get completed receipts
     */
    public function getCompletedReceipts(string $branchId, int $perPage = 15, ?string $orderType = null): LengthAwarePaginator
    {
        $query = GoodsReceipt::with(['purchaseOrder:id,order_type', 'purchaseOrder.supplier', 'invoice', 'items'])
            ->withCount('items as items_count')
            ->byBranch($branchId)
            ->completed();

        if ($orderType) {
            $query->whereHas('purchaseOrder', function ($q) use ($orderType) {
                $q->where('order_type', $orderType);
            });
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Use Resource class through paginator
        return $paginator->through(fn($receipt) => new GoodsReceiptListResource($receipt));
    }

    /**
     * Start receiving an order with items inspection data, document type, and variance actions
     *
     * @param PurchaseOrder $order
     * @param string $receivedBy
     * @param array $itemsData Array of items with inspection details: item_id, quantity_received, quality, temperature, expiration_date, photo, notes
     * @param string|null $documentType Optional: invoice, delivery_note, receipt_without_document
     * @param array|null $documentData Optional: Data for document type (invoice_data or delivery_note_data)
     * @param array|null $varianceData Optional: Single variance action object for all items with variance (action, note, photo, compensatory_order_data, deduct_data)
     * @param array $unlistedItems Optional: Unlisted items (gifts from supplier)
     * @return GoodsReceipt
     */
    public function startReceiving(
        PurchaseOrder $order,
        string $receivedBy,
        array $itemsData = [],
        ?string $documentType = null,
        ?array $documentData = null,
        ?array $varianceData = null,
        array $unlistedItems = []
    ): GoodsReceipt {
        return DB::transaction(function () use ($order, $receivedBy, $itemsData, $documentType, $documentData, $varianceData, $unlistedItems) {
            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'received_by' => $receivedBy,
                'status' => 'in_progress',
                'total_items_expected' => $order->items->count(),
                'expected_amount' => $order->total_amount,
                'inspection_started_at' => now(),
            ]);

            // Create a map of received items data by item_id
            $receivedItemsMap = collect($itemsData)->keyBy('item_id');

            // Create receipt items from order items with inspection data
            $receiptItemsMap = [];

            \Log::info('GoodsReceipt: Starting receipt items creation', [
                'receipt_id' => $receipt->id,
                'order_id' => $order->id,
                'order_items_count' => $order->items->count(),
                'items_data_count' => count($itemsData),
            ]);

            foreach ($order->items as $orderItem) {
                $receivedItemData = $receivedItemsMap->get($orderItem->id);

                // Get inspection data if provided, otherwise use defaults
                $quantityReceived = $receivedItemData['quantity_received'] ?? 0;
                $qualityReceived = isset($receivedItemData['quality'])
                    ? \Modules\Purchase\Enums\InspectionQuality::from($receivedItemData['quality'])
                    : null;
                $temperature = $receivedItemData['temperature'] ?? null;
                // Map expiration_date from request to expiry_date for model
                $expiryDate = null;
                if (isset($receivedItemData['expiration_date'])) {
                    $expiryDate = $receivedItemData['expiration_date'];
                } elseif (isset($receivedItemData['expiry_date'])) {
                    $expiryDate = $receivedItemData['expiry_date'];
                }
                $photo = $receivedItemData['photo'] ?? null;
                $notes = $receivedItemData['notes'] ?? null;

                // Calculate received total
                $receivedTotal = $quantityReceived * $orderItem->unit_price;

                // Use quantity_confirmed if available (for partial confirmation), otherwise use quantity_ordered
                $expectedQuantity = $orderItem->quantity_confirmed ?? $orderItem->quantity_ordered;
                $expectedTotal = $expectedQuantity * $orderItem->unit_price;

                $receiptItem = GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $orderItem->id,
                    'item_id' => $orderItem->item_id,
                    'item_name' => $orderItem->item_name,
                    'item_logo' => $orderItem->item_logo,
                    'unit_of_measurement' => $orderItem->unit_of_measurement,
                    'quantity_ordered' => $expectedQuantity, // Use confirmed quantity if available
                    'quantity_received' => $quantityReceived,
                    'quality_ordered' => $orderItem->quality_ordered,
                    'quality_received' => $qualityReceived,
                    'temperature' => $temperature,
                    'expiry_date' => $expiryDate,
                    'photo' => $photo,
                    'notes' => $notes,
                    'unit_price' => $orderItem->unit_price,
                    'expected_total' => $expectedTotal, // Calculate based on confirmed quantity
                    'received_total' => $receivedTotal,
                ]);

                // Calculate variance for the item
                $receiptItem->calculateVariance();

                // Store receipt item in map for variance processing
                $receiptItemsMap[$orderItem->id] = $receiptItem;

                \Log::info('GoodsReceipt: Created receipt item', [
                    'receipt_item_id' => $receiptItem->id,
                    'item_name' => $receiptItem->item_name,
                    'quantity_received' => $receiptItem->quantity_received,
                ]);
            }

            \Log::info('GoodsReceipt: Receipt items creation completed', [
                'receipt_id' => $receipt->id,
                'items_created_count' => count($receiptItemsMap),
            ]);

            // Process unlisted items (gifts from supplier)
            foreach ($unlistedItems as $unlistedItemData) {
                $this->addUnlistedItem($receipt, $unlistedItemData);
            }

            // Calculate receipt summary
            $receipt->calculateSummary();

            // Refresh receipt to get updated items with variances
            $receipt->refresh();
            $receipt->load('items');

            // Create variance records ONLY if varianceData is provided
            // This ensures variances are only created when user explicitly reports them
            $variancesMap = [];
            if (!empty($varianceData)) {
                // If compensatory_order, create variances only for items in variance.items
                if ($varianceData['action'] === 'compensatory_order' && !empty($varianceData['items'])) {
                    $varianceItemIds = collect($varianceData['items'])->pluck('item_id')->toArray();
                    foreach ($receipt->items as $item) {
                        if (in_array($item->purchase_order_item_id, $varianceItemIds)) {
                            // Check if variance already exists
                            $variance = $item->variance;
                            if (!$variance) {
                                $variance = $this->varianceService->createVariance($receipt, $item);
                            }
                            $variancesMap[$item->purchase_order_item_id] = $variance;
                        }
                    }
                } else {
                    // For other actions (accept, deduct_from_invoice), create variances for all items with variance
                    // Check both has_variance attribute and actual variance values (fallback)
                    foreach ($receipt->items as $item) {
                        $hasVariance = $item->has_variance
                            || $item->quantity_variance != 0
                            || $item->has_quality_variance;

                        if ($hasVariance) {
                            // Check if variance already exists
                            $variance = $item->variance;
                            if (!$variance) {
                                $variance = $this->varianceService->createVariance($receipt, $item);
                            }
                            $variancesMap[$item->purchase_order_item_id] = $variance;
                        }
                    }
                }
            }

            // Process variance action if provided (applies to all items with variance)
            // IMPORTANT: Variances are already created above, so even if action fails, variances will be saved
            if (!empty($varianceData) && !empty($variancesMap)) {
                $action = $varianceData['action'];
                $varianceNote = $varianceData['note'] ?? null;
                $variancePhoto = $varianceData['photo'] ?? null;

                // Apply action to all variances
                // Use try-catch to ensure variance records are saved even if action processing fails
                foreach ($variancesMap as $variance) {
                    try {
                        // Process the action
                        match ($action) {
                            'accept' => $this->varianceService->acceptVariance($variance),
                            'compensatory_order' => $this->handleCompensatoryOrder(
                                $variance,
                                $order,
                                $varianceData['items'] ?? [],
                                $varianceNote,
                                $variancePhoto
                            ),
                            'deduct_from_invoice' => $this->varianceService->deductFromInvoice(
                                $variance,
                                $varianceData['deduct_data']['amount'] ?? 0,
                                $varianceData['deduct_data']['reason'] ?? 'short_quantity',
                                $varianceData['deduct_data']['notes'] ?? $varianceNote
                            ),
                        };
                    } catch (\Exception $e) {
                        // Log error but don't fail the entire transaction
                        // Variance record is already created and will be saved
                        \Log::error('Error processing variance action', [
                            'variance_id' => $variance->id,
                            'action' => $action,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        // Update variance status to indicate action failed
                        // Use DB::table to avoid model events that might cause issues
                        DB::table('purchase_variances')
                            ->where('id', $variance->id)
                            ->update([
                                'status' => 'pending',
                                'additional_notes' => ($variance->additional_notes ?? '') . "\n[Error processing action: " . $e->getMessage() . ']',
                                'updated_at' => now(),
                            ]);
                    }
                }
            }

            // Log variance creation for debugging
            if (!empty($variancesMap)) {
                \Log::info('GoodsReceipt: Variances created', [
                    'receipt_id' => $receipt->id,
                    'variances_count' => count($variancesMap),
                    'variance_ids' => collect($variancesMap)->pluck('id')->toArray(),
                ]);
            }

            // Handle document type if provided
            if ($documentType) {
                $docType = DocumentType::from($documentType);
                $receipt->setDocumentType($docType);

                match ($documentType) {
                    'invoice' => $this->createInvoice($receipt, $documentData ?? []),
                    'delivery_note' => $this->createDeliveryNote($receipt, $documentData ?? []),
                    'receipt_without_document' => $this->createReceiptWithoutDocument($receipt),
                };
            }

            // Update order status based on current status
            // If fully approved, transition to confirmed when received
            // If partially approved, transition to partial_confirmed when received
            $currentStatus = $order->status;
            if ($currentStatus === OrderStatus::FULLY_APPROVED) {
                $order->transitionTo(OrderStatus::CONFIRMED);
            } elseif ($currentStatus === OrderStatus::PARTIAL_APPROVED) {
                $order->transitionTo(OrderStatus::PARTIAL_CONFIRMED);
            } else {
                // For other statuses, transition to delivered as before
                $order->transitionTo(OrderStatus::DELIVERED);
            }

            $this->timelineService->logInspectionStarted($receipt);
            $this->timelineService->logReceivingStarted($order);

            // Save delivered stage if invoice was created
            $receipt->refresh();
            if ($receipt->invoice || $documentType === 'invoice') {
                $this->trackingService->saveDeliveredStage($order);
            }

            // Check if receipt should be completed automatically (no variances)
            $receipt->refresh();
            $receipt->load('items');

            // If no variances after processing, complete inspection and close order
            if (!$receipt->hasVariances) {
                // Complete inspection
                $receipt->completeInspection();

                // Refresh receipt to get updated status
                $receipt->refresh();

                // Update order item quantities
                foreach ($receipt->items as $item) {
                    if ($item->purchase_order_item_id) {
                        $item->purchaseOrderItem->markAsReceived(
                            $item->quantity_received,
                            $item->quality_received?->value
                        );
                    }
                }

                // Log inspection completed
                $this->timelineService->logInspectionCompleted($receipt);

                // Refresh order to get latest receipt
                $order->refresh();
                $order->load('latestGoodsReceipt');

                // Save order confirmation stage
                $this->trackingService->saveOrderConfirmationStage($order);

                // Close order
                $order->close();
            } else {
                // When there are variances: still sync quantity_received and quality_received to order items
                // so that "Order via Supplier" / product details show quality for variance items
                foreach ($receipt->items as $item) {
                    if ($item->purchase_order_item_id) {
                        $item->purchaseOrderItem->markAsReceived(
                            $item->quantity_received,
                            $item->quality_received?->value
                        );
                    }
                }

                // The controller pre-closed the order before receiving started;
                // markAsReceived() above overrides item statuses. Re-sync them.
                $order->refresh();
                if ($order->status === OrderStatus::CLOSED) {
                    $order->items()->update(['status' => OrderItemStatus::CLOSED->value]);
                }
            }

            return $receipt->fresh(['items', 'purchaseOrder', 'variances', 'invoice']);
        });
    }

    /**
     * Inspect an item
     */
    public function inspectItem(GoodsReceiptItem $item, array $data): GoodsReceiptItem
    {
        $item->inspect($data);

        // Update parent receipt
        $item->goodsReceipt->calculateSummary();

        return $item->fresh();
    }

    /**
     * Add unlisted item (gift)
     */
    public function addUnlistedItem(GoodsReceipt $receipt, array $data): GoodsReceiptItem
    {
        return GoodsReceiptItem::create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $data['item_id'] ?? null,
            'item_name' => $data['item_name'],
            'item_logo' => $data['item_logo'] ?? null,
            'unit_of_measurement' => $data['unit'] ?? 'kg',
            'quantity_ordered' => 0,
            'quantity_received' => $data['quantity'],
            'quality_received' => $data['quality'] ?? 'normal',
            'unit_price' => $data['price_per_unit'] ?? 0,
            'expected_total' => 0,
            'received_total' => ($data['quantity'] ?? 0) * ($data['price_per_unit'] ?? 0),
            'is_unlisted' => true,
            'unlisted_reason' => $data['reason'] ?? 'Gift from supplier',
            'supplier_id' => $data['supplier_id'] ?? null,
            'temperature' => $data['temperature'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'photo' => $data['photo'] ?? null,
        ]);
    }

    /**
     * Set delivery details
     */
    public function setDeliveryDetails(GoodsReceipt $receipt, array $details): GoodsReceipt
    {
        $receipt->update([
            'driver_name' => $details['driver_name'] ?? null,
            'driver_contact' => $details['driver_contact'] ?? null,
            'driver_image' => $details['driver_image'] ?? null,
            'vehicle_number' => $details['vehicle_number'] ?? null,
            'arrival_time' => $details['arrival_time'] ?? now(),
            'delivery_address' => $details['delivery_address'] ?? null,
            'delivery_notes' => $details['delivery_notes'] ?? null,
        ]);

        return $receipt->fresh();
    }

    /**
     * Set document type
     */
    public function setDocumentType(GoodsReceipt $receipt, DocumentType $type): void
    {
        $receipt->setDocumentType($type);
    }

    /**
     * Create invoice for receipt
     */
    public function createInvoice(GoodsReceipt $receipt, array $data): PurchaseInvoice
    {
        // Calculate amounts from receipt (amount_before_tax and tax_rate are calculated automatically)
        $amountBeforeTax = $receipt->received_amount;
        $taxRate = 15.00; // Default tax rate
        $taxAmount = $this->calculationService->calculateVAT($amountBeforeTax, $taxRate);
        $totalAmount = $amountBeforeTax + $taxAmount;

        $invoice = PurchaseInvoice::create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_id' => $receipt->purchase_order_id,
            'supplier_id' => $receipt->purchaseOrder->supplier_id,
            'invoice_number' => $data['invoice_number'] ?? null,
            'invoice_date' => $data['invoice_date'],
            'due_date' => $data['due_date'] ?? $this->calculationService->calculateDueDate($data['invoice_date']),
            'payment_terms' => $data['payment_terms'] ?? 'Net 30 days',
            'amount_before_tax' => $amountBeforeTax,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'final_amount' => $totalAmount,
            'status' => 'pending',
        ]);

        // Handle photo upload (photo is already stored as path string)
        if (!empty($data['photo'])) {
            // Create document record for the photo
            OrderDocument::create([
                'documentable_type' => get_class($invoice),
                'documentable_id' => $invoice->id,
                'type' => DocumentType::INVOICE,
                'file_path' => $data['photo'],
                'file_name' => basename($data['photo']),
                'original_name' => basename($data['photo']),
                'mime_type' => 'image/jpeg', // Default, can be improved
                'file_size' => 0, // Can be improved by reading file size
                'title' => 'Invoice Photo',
                'description' => $data['notes'] ?? null,
                'uploaded_by' => \Illuminate\Support\Facades\Auth::id(),
                'uploaded_by_type' => \Illuminate\Support\Facades\Auth::user() ? get_class(\Illuminate\Support\Facades\Auth::user()) : null,
                'is_active' => true,
            ]);
        }

        $this->timelineService->logInvoiceUploaded($receipt, $invoice);

        return $invoice;
    }

    /**
     * Complete inspection and process variances
     */
    public function completeInspection(GoodsReceipt $receipt): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt) {
            $receipt->completeInspection();

            // Create variance records for items with discrepancies
            foreach ($receipt->items as $item) {
                if ($item->has_variance) {
                    $this->varianceService->createVariance($receipt, $item);
                }
            }

            // Update order item quantities
            foreach ($receipt->items as $item) {
                if ($item->purchase_order_item_id) {
                    $item->purchaseOrderItem->markAsReceived(
                        $item->quantity_received,
                        $item->quality_received?->value
                    );
                }
            }

            $this->timelineService->logInspectionCompleted($receipt);

            // Save tracking stages
            $order = $receipt->purchaseOrder;
            $this->trackingService->saveOrderConfirmationStage($order);

            if ($receipt->hasVariances) {
                $this->trackingService->saveVarianceLoggedStage($order);
            }

            // Always close order and set all items to CLOSED when inspection is completed (with or without variances)
            $receipt->purchaseOrder->close();

            return $receipt->fresh(['items', 'variances', 'invoice']);
        });
    }

    /**
     * Save receipt as draft
     */
    public function saveDraft(GoodsReceipt $receipt): GoodsReceipt
    {
        $receipt->saveDraft();
        return $receipt->fresh();
    }

    /**
     * Continue editing draft
     */
    public function continueDraft(string $receiptId): ?GoodsReceipt
    {
        return GoodsReceipt::with(['items', 'purchaseOrder.items', 'invoice'])
            ->draft()
            ->find($receiptId);
    }

    /**
     * Delete draft
     */
    public function deleteDraft(GoodsReceipt $receipt): bool
    {
        if (!$receipt->is_draft) {
            return false;
        }

        return $receipt->delete();
    }

    /**
     * Receive order without prior order
     */
    public function receiveWithoutOrder(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data) {
            // Create a purchase order first
            $order = PurchaseOrder::create([
                'order_type' => $data['order_type'] ?? 'direct_supplier',
                'status' => OrderStatus::DELIVERED,
                'branch_id' => $data['branch_id'],
                'requested_by' => $data['received_by'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'total_amount' => 0,
                'actual_delivery_at' => now(),
            ]);

            // Create the receipt
            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->id,
                'branch_id' => $data['branch_id'],
                'received_by' => $data['received_by'],
                'status' => 'in_progress',
                'driver_name' => $data['driver_name'] ?? null,
                'driver_contact' => $data['driver_contact'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'arrival_time' => $data['arrival_time'] ?? now(),
                'delivery_address' => $data['delivery_address'] ?? null,
                'inspection_started_at' => now(),
            ]);

            // Add items
            foreach ($data['items'] as $itemData) {
                $total = ($itemData['quantity'] ?? 0) * ($itemData['price_per_unit'] ?? 0);

                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'item_id' => $itemData['item_id'] ?? null,
                    'item_name' => $itemData['product_name'],
                    'unit_of_measurement' => $itemData['unit'] ?? 'kg',
                    'quantity_ordered' => $itemData['quantity'],
                    'quantity_received' => $itemData['quantity'],
                    'quality_received' => $itemData['quality'] ?? 'normal',
                    'unit_price' => $itemData['price_per_unit'] ?? 0,
                    'expected_total' => $total,
                    'received_total' => $total,
                    'temperature' => $itemData['temperature'] ?? null,
                    'expiry_date' => $itemData['expiry_date'] ?? null,
                    'notes' => $itemData['note'] ?? null,
                ]);
            }

            $receipt->calculateSummary();

            return $receipt->fresh(['items', 'purchaseOrder']);
        });
    }

    /**
     * Get receipt details
     */
    public function getReceiptDetails(string $receiptId): ?GoodsReceipt
    {
        return GoodsReceipt::with([
            'items',
            'purchaseOrder.items',
            'purchaseOrder.supplier',
            'invoice',
            'variances',
            'documents',
            'timelines',
        ])->find($receiptId);
    }

    /**
     * Update item inspection with all required fields
     */
    public function updateItemInspection(GoodsReceiptItem $item, array $data): GoodsReceiptItem
    {
        $item->update([
            'quantity_received' => $data['quantity_received'] ?? $item->quantity_received,
            'quality_received' => isset($data['quality']) ? \Modules\Purchase\Enums\InspectionQuality::from($data['quality']) : $item->quality_received,
            'temperature' => $data['temperature'] ?? $item->temperature,
            'expiry_date' => isset($data['expiry_date']) ? $data['expiry_date'] : $item->expiry_date,
            'photo' => $data['photo'] ?? $item->photo,
            'notes' => $data['notes'] ?? $item->notes,
        ]);

        $item->calculateVariance();
        $item->goodsReceipt->calculateSummary();

        return $item->fresh();
    }

    /**
     * Create delivery note for receipt
     */
    public function createDeliveryNote(GoodsReceipt $receipt, array $data): void
    {
        $receipt->setDocumentType(DocumentType::DELIVERY_NOTE);

        // Handle file upload if provided
        if (!empty($data['file'])) {
            OrderDocument::upload($receipt, $data['file'], DocumentType::DELIVERY_NOTE, 'Delivery Note');
        }

        // Log via OrderTimeline directly
        \Modules\Purchase\Models\OrderTimeline::log(
            $receipt,
            \Modules\Purchase\Enums\TimelineEventType::DOCUMENT_ATTACHED,
            'Delivery Note Created',
            'Delivery note was created for receipt'
        );
    }

    /**
     * Create receipt without document
     */
    public function createReceiptWithoutDocument(GoodsReceipt $receipt): void
    {
        $receipt->setDocumentType(DocumentType::RECEIPT_WITHOUT_DOCUMENT);

        // Log via OrderTimeline directly
        \Modules\Purchase\Models\OrderTimeline::log(
            $receipt,
            \Modules\Purchase\Enums\TimelineEventType::DOCUMENT_ATTACHED,
            'Receipt Without Document',
            'Receipt was created without document'
        );
    }

    /**
     * Get inspection details by order ID
     */
    public function getInspectionDetailsByOrderId(string $orderId, array $requestData = []): ?array
    {
        $order = PurchaseOrder::with([
            'goodsReceipts.items',
            'goodsReceipts.items.variance',
            'items',
            'supplier',
            'branch',
        ])->find($orderId);

        if (!$order) {
            return null;
        }

        // Get the latest receipt (most recent) if exists
        $receipt = $order->goodsReceipts()->latest()->first();

        // Use receipt delivery details if available, otherwise fallback to order
        // delivery_address should come from receipt (supplier input when creating out-delivery)
        // If receipt exists but delivery_address is null, return null (supplier hasn't entered it yet)
        // If no receipt exists, use branch location as fallback
        $deliveryDetails = [
            'driver_name' => $receipt?->driver_name ?? $order->driver_name,
            'contact_number' => $receipt?->driver_contact ?? $order->driver_contact,
            'vehicle_number' => $receipt?->vehicle_number ?? $order->vehicle_number,
            'arrival_time' => $receipt?->arrival_time?->format('Y-m-d H:i:s')
                ?? $order->actual_delivery_at?->format('Y-m-d H:i:s'),
            'delivery_address' => $receipt
                ? ($receipt->delivery_address ?? null)
                : ($order->branch?->location ?? null),
        ];

        // Exclude cancelled order items from inspection (only show non-cancelled items)
        $cancelledStatuses = OrderItemStatus::cancelledStatusValues();
        $isCancelled = fn ($item) => in_array(
            $item->status instanceof OrderItemStatus ? $item->status->value : ($item->status ?? ''),
            $cancelledStatuses,
            true
        );
        $cancelledOrderItemIds = $order->items->filter($isCancelled)->pluck('id')->all();

        // If receipt exists, use receipt items, otherwise use order items
        if ($receipt && $receipt->items->isNotEmpty()) {
            $receipt->load(['items.variance']);
            $inspectionItems = $receipt->items
                ->filter(fn ($item) => ! in_array($item->purchase_order_item_id ?? null, $cancelledOrderItemIds, true))
                ->map(fn ($item) => $this->formatInspectionItem($item))
                ->values()
                ->all();
        } else {
            // Use order items as fallback (not inspected yet) — only non-cancelled
            $inspectionItems = $order->items
                ->reject($isCancelled)
                ->map(function ($orderItem) {
                    $expectedQuantity = $orderItem->quantity_confirmed ?? $orderItem->quantity_ordered;

                    return [
                        'item_id' => $orderItem->id,
                        'product_name' => $orderItem->item_name,
                        'item_logo' => $orderItem->item_logo_url ?? null,
                        'qty_ordered' => (float) $expectedQuantity,
                        'qty_received' => 0.0,
                        'unit' => $orderItem->unit_of_measurement,
                        'quality' => 'normal',
                        'price' => (float) $orderItem->unit_price,
                        'temperature' => null,
                        'expiration_date' => null,
                        'photo' => null,
                        'note' => null,
                        'variance' => null,
                    ];
                })
                ->values()
                ->all();
        }

        $response = [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'supplier_name' => $order->supplier?->name ?? null,
            'delivery_details' => $deliveryDetails,
            'goods_inspection' => $inspectionItems,
        ];

        // Add unit_price and order_number from request if supplier_name and order_number are in request
        if (
            !empty($requestData['supplier_name']) &&
            !empty($requestData['order_number'])
        ) {
            if (isset($requestData['unit_price'])) {
                $response['unit_price'] = $requestData['unit_price'];
            }
            $response['request_order_number'] = $requestData['order_number'];
        }

        return $response;
    }

    /**
     * Format inspection item for response
     */
    private function formatInspectionItem(GoodsReceiptItem $item): array
    {
        $variance = $item->variance;
        $hasVariance = $item->has_variance;

        $varianceDetails = null;
        if ($hasVariance && $variance) {
            $varianceType = $variance->variance_type?->value;
            $varianceAmount = abs($item->quantity_variance);
            $varianceUnit = $item->unit_of_measurement;

            $varianceMessage = match ($varianceType) {
                'short' => "Item delivered is {$varianceAmount}{$varianceUnit} lower than requested & Confirmed Qty.",
                'damage' => "Item quality variance detected.",
                'both' => "Item delivered is {$varianceAmount}{$varianceUnit} lower than requested & Confirmed Qty with quality issues.",
                default => "Variance detected for this item.",
            };

            $varianceDetails = [
                'variance_detected' => true,
                'variance_type' => $varianceType,
                'variance_amount' => (float) $varianceAmount,
                'variance_unit' => $varianceUnit,
                'variance_message' => $varianceMessage,
            ];
        }

        return [
            // Use purchase_order_item_id (order line id) for start endpoint
            // Note: null for unlisted items (gifts) - these cannot be used in start endpoint
            'item_id' => $item->purchase_order_item_id,
            'product_name' => $item->item_name,
            'item_logo' => $item->item_logo_url,
            'qty_ordered' => (float) $item->quantity_ordered,
            'qty_received' => (float) $item->quantity_received,
            'unit' => $item->unit_of_measurement,
            'quality' => $item->quality_received?->value ?? 'normal',
            'price' => (float) $item->unit_price,
            'temperature' => $item->temperature ? (float) $item->temperature : null,
            'expiration_date' => $item->expiry_date?->format('Y-m-d'),
            'photo' => $item->item_logo,
            'note' => $item->notes,
            'variance' => $varianceDetails,
        ];
    }

    /**
     * Get comprehensive receipt summary
     */
    public function getReceiptSummary(string $receiptId): array
    {
        $receipt = GoodsReceipt::with([
            'items',
            'purchaseOrder.supplier',
            'purchaseOrder.items',
            'invoice',
            'variances',
            'documents',
            'receivedBy',
        ])->find($receiptId);

        if (!$receipt) {
            return [];
        }

        return [
            'inspection_summary' => [
                'number_of_items_received' => $receipt->total_items_received,
                'number_of_quantity_variances' => $receipt->quantity_variances,
                'total_amount' => (float) $receipt->received_amount,
                'driver_name' => $receipt->driver_name,
                'contact_number' => $receipt->driver_contact,
                'vehicle_number' => $receipt->vehicle_number,
                'arrival_time' => $receipt->arrival_time?->format('Y-m-d H:i:s'),
            ],
            'goods_inspection' => $receipt->items->map(function ($item) {
                return [
                    'item_name' => $item->item_name,
                    'item_logo' => $item->item_logo_url,
                    'quantity_ordered' => (float) $item->quantity_ordered,
                    'quantity_received' => (float) $item->quantity_received,
                    'quality' => $item->quality_received?->value,
                    'temperature' => $item->temperature,
                    'expiration_date' => $item->expiry_date?->format('Y-m-d'),
                    'photo' => $item->photo_url,
                    'notes' => $item->notes,
                ];
            }),
            'document_summary' => [
                'document_type' => $receipt->document_type?->value,
                'invoice_details' => $receipt->invoice ? [
                    'invoice_number' => $receipt->invoice->invoice_number,
                    'invoice_date' => $receipt->invoice->invoice_date?->format('Y-m-d'),
                    'supplier_name' => $receipt->purchaseOrder->supplier?->name,
                    'attachment' => $receipt->invoice->file_url,
                ] : null,
            ],
            'variance_summary' => $receipt->variances->map(function ($variance) {
                return [
                    'item_name' => $variance->item_name,
                    'variance_type' => $variance->variance_type?->value,
                    'amount_variance' => (float) $variance->variance_amount,
                    'required_action' => $variance->action?->value,
                    'amount_to_deduct' => $variance->amount_to_deduct ? (float) $variance->amount_to_deduct : null,
                    'reason_for_deduction' => $variance->deduction_reason,
                    'additional_note' => $variance->additional_notes,
                ];
            }),
            'financial_summary' => $receipt->invoice ? [
                'amount_before_tax' => (float) $receipt->invoice->amount_before_tax,
                'vat' => (float) $receipt->invoice->tax_amount,
                'total_amount' => (float) $receipt->invoice->total_amount,
            ] : null,
        ];
    }

    /**
     * Receive internal transfer order
     */
    public function receiveInternalTransfer(PurchaseOrder $order, string $receivedBy, array $deliveryDetails = []): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $receivedBy, $deliveryDetails) {
            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'received_by' => $receivedBy,
                'status' => 'in_progress',
                'driver_name' => $deliveryDetails['driver_name'] ?? null,
                'driver_contact' => $deliveryDetails['driver_contact'] ?? null,
                'vehicle_number' => $deliveryDetails['vehicle_number'] ?? null,
                'arrival_time' => $deliveryDetails['arrival_time'] ?? now(),
                'delivery_address' => $deliveryDetails['delivery_address'] ?? null,
                'total_items_expected' => $order->items->count(),
                'expected_amount' => $order->total_amount,
                'inspection_started_at' => now(),
            ]);

            // Create receipt items from order items (internal transfer has different structure)
            foreach ($order->items as $orderItem) {
                // Use quantity_confirmed if available (for partial confirmation), otherwise use quantity_ordered
                $expectedQuantity = $orderItem->quantity_confirmed ?? $orderItem->quantity_ordered;
                $expectedTotal = $expectedQuantity * $orderItem->unit_price;

                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $orderItem->id,
                    'item_id' => $orderItem->item_id,
                    'item_name' => $orderItem->item_name,
                    'item_logo' => $orderItem->item_logo,
                    'unit_of_measurement' => $orderItem->unit_of_measurement,
                    'quantity_ordered' => $expectedQuantity, // Use confirmed quantity if available
                    'quantity_received' => $expectedQuantity, // For internal transfer, received equals expected
                    'quality_ordered' => $orderItem->quality_ordered,
                    'unit_price' => $orderItem->unit_price,
                    'expected_total' => $expectedTotal, // Calculate based on confirmed quantity
                    'received_total' => $expectedTotal,
                ]);
            }

            // Auto-complete and move to history
            $receipt->completeInspection();

            // For internal transfer, directly update status to CLOSED (bypass transition rules)
            $order->update([
                'status' => OrderStatus::CLOSED->value,
                'closed_at' => now(),
            ]);
            $order->items()->update(['status' => OrderItemStatus::CLOSED->value]);

            $this->timelineService->logInspectionCompleted($receipt);
            $this->timelineService->logOrderClosed($order);

            return $receipt->fresh(['items', 'purchaseOrder']);
        });
    }

    /**
     * Get draft details
     */
    public function getDraftDetails(string $receiptId): ?GoodsReceipt
    {
        return GoodsReceipt::with([
            'items',
            'purchaseOrder.supplier',
            'purchaseOrder.fromBranch',
            'purchaseOrder.requestedBy',
            'purchaseOrder.items',
            'invoice',
            'variances',
            'documents',
            'timelines',
        ])->draft()->find($receiptId);
    }

    /**
     * Get missing goods details
     */
    public function getMissingGoodsDetails(string $receiptId): ?GoodsReceipt
    {
        return GoodsReceipt::with([
            'items',
            'purchaseOrder.supplier',
            'purchaseOrder.items',
            'invoice',
            'variances.compensatoryOrder',
            'variances.timelines',
            'timelines',
        ])->withVariances()->find($receiptId);
    }

    /**
     * Get complete goods details
     */
    public function getCompleteGoodsDetails(string $receiptId): ?GoodsReceipt
    {
        return GoodsReceipt::with([
            'items',
            'purchaseOrder.supplier',
            'purchaseOrder.items',
            'invoice',
            'variances',
            'timelines',
        ])->completed()->find($receiptId);
    }

    /**
     * Get order tracking stages
     */
    public function getOrderTracking(string $orderId): array
    {
        $order = PurchaseOrder::find($orderId);

        if (!$order) {
            return [];
        }

        // Get tracking stages from the database
        $trackingService = app(\Modules\Purchase\Services\OrderTrackingService::class);
        $stages = $trackingService->getOrderTrackingStages($orderId);

        // If no stages exist in database, try to create them from current order state (backward compatibility)
        if (empty($stages)) {
            $stages = $this->getOrderTrackingFromCurrentState($order);
        }

        // Add variance summary to variance_logged stage when present
        if (!empty($stages['variance_logged'])) {
            $order->loadMissing('latestGoodsReceipt');
            $receiptId = $order->latestGoodsReceipt?->id;
            if ($receiptId) {
                $varianceService = app(\Modules\Purchase\Services\VarianceService::class);
                $stages['variance_logged']['variance_summary'] = $varianceService->getVarianceSummary($receiptId);
            }
        }

        return $stages;
    }

    /**
     * Get order tracking from current state (backward compatibility for existing orders)
     */
    private function getOrderTrackingFromCurrentState(PurchaseOrder $order): array
    {
        $order->load([
            'items.documents',
            'goodsReceipts.items',
            'goodsReceipts.invoice',
            'goodsReceipts.variances',
            'supplier',
        ]);

        $latestReceipt = $order->latestGoodsReceipt;
        $stages = [];

        // Stage 1: Preparing
        if ($order->status === \Modules\Purchase\Enums\OrderStatus::PREPARING) {
            $stages['preparing'] = [
                'status' => 'preparing',
                'items' => $order->items->map(function ($item) {
                    return [
                        'item_name' => $item->item_name,
                        'item_logo' => $item->item_logo,
                        'item_unit' => $item->unit_of_measurement ?? 'kg',
                        'requested_quantity' => (float) $item->quantity_ordered,
                        'status' => 'Preparing',
                        'quality_certificate' => FileResource::makeOrNull(
                            $item->documents()
                                ->where('type', DocumentType::QUALITY_CERTIFICATE)
                                ->first()
                        )?->toArray(request()),
                    ];
                })->toArray(),
            ];
        }

        // Stage 2: Out for Delivery
        if ($order->status === \Modules\Purchase\Enums\OrderStatus::ON_THE_WAY) {
            $driverPhotoPath = $order->driver_photo ?? $latestReceipt?->driver_image ?? null;
            $stages['out_for_delivery'] = [
                'status' => 'out_for_delivery',
                'arrival_time' => $order->expected_delivery_at?->format('Y-m-d H:i:s'),
                'driver_details' => [
                    'name' => $order->driver_name,
                    'photo' => FileResource::makeOrNull($driverPhotoPath)?->toArray(request()),
                    'vehicle_number' => $order->vehicle_number,
                ],
                'delivery_address' => $latestReceipt?->delivery_address ?? null,
                'delivery_notes' => $latestReceipt?->delivery_notes,
            ];
        }

        // Stage 3: Delivered
        if ($order->status === \Modules\Purchase\Enums\OrderStatus::DELIVERED && $latestReceipt) {
            $latestReceipt->loadMissing(['invoice', 'documents']);
            $invoice = $latestReceipt->invoice;
            $deliveredStage = ['status' => 'delivered'];
            if ($invoice) {
                $deliveredStage['invoice_file'] = FileResource::makeOrNull($invoice)?->toArray(request());
            } elseif ($latestReceipt->document_type?->value === 'delivery_note') {
                $deliveryNoteDoc = $latestReceipt->documents
                    ->where('type', DocumentType::DELIVERY_NOTE)
                    ->first();
                if ($deliveryNoteDoc) {
                    $deliveredStage['delivery_note_file'] = FileResource::makeOrNull($deliveryNoteDoc)?->toArray(request());
                }
            }

            // Add delivery_photos from order if available
            if ($order->delivery_photos && is_array($order->delivery_photos) && !empty($order->delivery_photos)) {
                $uploadedAt = $order->actual_delivery_at?->format('Y-m-d H:i:s')
                    ?? $order->received_at?->format('Y-m-d H:i:s')
                    ?? null;

                $deliveredStage['delivery_photos'] = array_map(function ($photoPath) use ($uploadedAt) {
                    if (!is_string($photoPath)) {
                        return FileResource::makeOrNull($photoPath)?->toArray(request());
                    }

                    // Get file info from storage
                    $fileType = pathinfo($photoPath, PATHINFO_EXTENSION);
                    $fileSize = null;
                    try {
                        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($photoPath)) {
                            $fileSize = \Illuminate\Support\Facades\Storage::disk('public')->size($photoPath);
                        }
                    } catch (\Throwable) {
                        // Keep null on error
                    }

                    return FileResource::makeOrNull([
                        'file_path' => $photoPath,
                        'file_name' => basename($photoPath),
                        'file_type' => $fileType,
                        'file_size' => $fileSize,
                        'created_at' => $uploadedAt,
                    ])?->toArray(request());
                }, $order->delivery_photos);
            }

            $stages['delivered'] = $deliveredStage;
        }

        // Stage 4: Order Confirmation
        if ($latestReceipt && $latestReceipt->is_completed) {
            $latestReceipt->loadMissing(['invoice', 'items']);
            $order->loadMissing('supplier');
            $invoice = $latestReceipt->invoice;
            $receiptDateTime = $latestReceipt->inspection_completed_at
                ?? $latestReceipt->updated_at
                ?? $latestReceipt->created_at;

            $stages['order_confirmation'] = [
                'status' => 'confirmed',
                'receipt_details' => [
                    'date_time' => $receiptDateTime?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                    'inspection_summary' => [
                        'number_of_items' => $latestReceipt->total_items_received,
                        'quantity_variance' => $latestReceipt->quantity_variances,
                        'total_amount' => (float) $latestReceipt->received_amount,
                        'driver_name' => $latestReceipt->driver_name ?? $order->driver_name,
                        'contact_number' => $latestReceipt->driver_contact ?? $order->driver_contact ?? null,
                        'vehicle_number' => $latestReceipt->vehicle_number ?? $order->vehicle_number,
                        'arrival_time' => $latestReceipt->arrival_time?->format('Y-m-d H:i:s')
                            ?? $order->expected_delivery_at?->format('Y-m-d H:i:s'),
                    ],
                    'goods_inspections' => $latestReceipt->items->map(function ($item) {
                        return [
                            'item_name' => $item->item_name,
                            'item_logo' => $item->item_logo_url,
                            'item_unit' => $item->unit_of_measurement ?? 'kg',
                            'quantity_ordered' => (float) $item->quantity_ordered,
                            'quantity_received' => (float) $item->quantity_received,
                            'quality' => $item->quality_received?->value,
                            'variance_type' => $item->variance_type?->value,
                            'amount_variance' => $item->variance_amount ? (float) $item->variance_amount : null,
                            'temperature' => $item->temperature,
                            'expiration_date' => $item->expiry_date?->format('Y-m-d'),
                            'item_image' => FileResource::makeOrNull($item->photo)?->toArray(request()),
                            'additional_note' => $item->notes,
                        ];
                    })->toArray(),
                    'document_type' => $latestReceipt->document_type?->value,
                    'financial_summary' => $invoice ? [
                        'invoice_number' => $invoice->invoice_number,
                        'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
                        'supplier_name' => $order->supplier?->name,
                        'amount_before_tax' => (float) $invoice->amount_before_tax,
                        'vat' => (float) $invoice->tax_amount,
                        'total_amount' => (float) $invoice->total_amount,
                    ] : null,
                ],
            ];
        }

        // Stage 5: Variance Logged
        if ($latestReceipt && $latestReceipt->hasVariances) {
            $varianceService = app(\Modules\Purchase\Services\VarianceService::class);
            $stages['variance_logged'] = [
                'status' => 'variance_logged',
                'variance_summary' => $varianceService->getVarianceSummary($latestReceipt->id),
                'variances' => $latestReceipt->variances->map(function ($variance) {
                    return [
                        'item_name' => $variance->item_name,
                        'item_logo' => $variance->item_logo,
                        'item_unit' => $variance->goodsReceiptItem?->unit_of_measurement ?? 'kg',
                        'temperature' => $variance->goodsReceiptItem?->temperature ?? null,
                        'quantity_ordered' => (float) $variance->quantity_ordered,
                        'quantity_received' => (float) $variance->quantity_received,
                        'quality' => $variance->quality_received?->value,
                        'variance_type' => $variance->variance_type?->value,
                        'amount_variance' => (float) $variance->variance_amount,
                        'supplier_response' => $variance->supplier_response,
                        'supplier_decision_status' => $variance->status,
                        'reported_on' => $variance->created_at?->format('Y-m-d H:i:s'),
                        'responded_on' => $variance->responded_at?->format('Y-m-d H:i:s'),
                        'supplier_name' => $variance->purchaseOrder->supplier?->name,
                        'supplier_image' => $variance->purchaseOrder->supplier?->image,
                        'rejection_reason' => $variance->status === 'supplier_rejected' ? $variance->supplier_response : null,
                        'rejection_date_time' => $variance->status === 'supplier_rejected' ? $variance->responded_at?->format('Y-m-d H:i:s') : null,
                    ];
                })->toArray(),
            ];
        }

        return $stages;
    }

    /**
     * Handle compensatory order: Create new PurchaseOrder with variance items
     */
    private function handleCompensatoryOrder(
        PurchaseVariance $variance,
        PurchaseOrder $originalOrder,
        array $items,
        ?string $note = null,
        ?string $photo = null
    ): CompensatoryOrder {
        // Create compensatory order record first
        $compensatory = $this->varianceService->createCompensatoryOrder(
            $variance,
            [
                'items' => $items,
                'notes' => $note,
                'photos' => $photo ? [$photo] : [],
            ]
        );

        // Get order items from variance.items (order line IDs)
        $varianceItemsMap = collect($items)->keyBy('item_id');
        $orderItems = $originalOrder->items()
            ->whereIn('id', $varianceItemsMap->keys()->toArray())
            ->get();

        if ($orderItems->isEmpty()) {
            return $compensatory;
        }

        // Variances keyed by purchase_order_item_id so we can fallback quality when order item has null
        $receipt = $variance->goodsReceipt;
        $receipt?->loadMissing(['variances.goodsReceiptItem']);
        $variancesByOrderItemId = $receipt
            ? $receipt->variances->keyBy(fn (PurchaseVariance $v) => $v->goodsReceiptItem?->purchase_order_item_id)
            : collect();

        // Prepare items data for new order
        // Use quantity from variance items, not from original order
        // addItem expects 'quantity' and 'quality' (not quality_ordered) to persist in DB
        $newOrderItems = $orderItems->map(function ($orderItem) use ($varianceItemsMap, $variancesByOrderItemId) {
            $varianceItem = $varianceItemsMap->get($orderItem->id);
            $quantity = $varianceItem['quantity'] ?? $orderItem->quantity_ordered;

            // Validate quantity exists and is greater than 0
            if (!isset($quantity) || $quantity <= 0) {
                throw new \InvalidArgumentException(
                    "Quantity is required and must be greater than 0 for item {$orderItem->item_name}"
                );
            }

            $varianceForItem = $variancesByOrderItemId->get($orderItem->id);
            $quality = $orderItem->quality_ordered?->value
                ?? $varianceForItem?->quality_ordered?->value;

            return [
                'item_id' => $orderItem->item_id,
                'item_name' => $orderItem->item_name,
                'item_logo' => $orderItem->item_logo,
                'item_sku' => $orderItem->item_sku,
                'category' => $orderItem->category,
                'subcategory' => $orderItem->subcategory,
                'quantity' => $quantity,
                'unit_of_measurement' => $orderItem->unit_of_measurement,
                'unit_price' => $orderItem->unit_price,
                'quality' => $quality, // addItem expects 'quality' to save quality_ordered in DB
            ];
        })->toArray();

        // Create new PurchaseOrder with same supplier and branch
        $newOrder = $this->orderService->createOrder([
            'order_type' => $originalOrder->order_type->value,
            'status' => OrderStatus::VARIANCE->value,
            'branch_id' => $originalOrder->branch_id,
            'requested_by' => $originalOrder->requested_by,
            'supplier_id' => $originalOrder->supplier_id,
            'from_branch_id' => $originalOrder->from_branch_id,
            'to_branch_id' => $originalOrder->to_branch_id,
            'quality_level' => $originalOrder->quality_level?->value,
            'processing_time' => $originalOrder->processing_time?->value,
            'priority' => $originalOrder->priority?->value ?? 'normal',
            'items' => $newOrderItems,
        ]);

        // Update compensatory order with new_order_id
        // Note: Check if supplier exists in purchase_suppliers table (legacy constraint)
        // If not, set to null since foreign key allows null
        // TODO: Create migration to update foreign key from purchase_suppliers to suppliers
        $reorderSupplierId = null;
        if ($originalOrder->supplier_id) {
            // Check if supplier exists in purchase_suppliers table (for foreign key constraint)
            $supplierExists = DB::table('purchase_suppliers')
                ->where('id', $originalOrder->supplier_id)
                ->exists();

            if (!$supplierExists) {
                // Log warning if supplier doesn't exist in purchase_suppliers
                // The supplier might be in the new suppliers table, but the FK constraint
                // still references purchase_suppliers (needs migration fix)
                \Log::warning('Supplier not found in purchase_suppliers table for compensatory order', [
                    'supplier_id' => $originalOrder->supplier_id,
                    'order_id' => $originalOrder->id,
                    'compensatory_order_id' => $compensatory->id,
                ]);
            }

            $reorderSupplierId = $supplierExists ? $originalOrder->supplier_id : null;
        }

        $compensatory->update([
            'new_order_id' => $newOrder->id,
            'reorder_supplier_id' => $reorderSupplierId,
            'status' => 'ordered',
        ]);

        return $compensatory;
    }
}
