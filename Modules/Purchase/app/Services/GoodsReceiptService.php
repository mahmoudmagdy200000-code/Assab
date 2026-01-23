<?php

namespace Modules\Purchase\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\VarianceType;
use Modules\Purchase\Models\CompensatoryOrder;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\OrderDocument;
use Modules\Purchase\Models\PurchaseInvoice;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseVariance;

class GoodsReceiptService
{
    public function __construct(
        private readonly TimelineService $timelineService,
        private readonly VarianceService $varianceService,
        private readonly CalculationService $calculationService,
        private readonly PurchaseOrderService $orderService
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

        // Transform results to return only requested fields
        $paginator->getCollection()->transform(function ($receipt) {
            $orderType = null;
            try {
                if ($receipt->purchaseOrder && $receipt->purchaseOrder->order_type) {
                    $orderTypeValue = $receipt->purchaseOrder->order_type;
                    if ($orderTypeValue instanceof \BackedEnum) {
                        $orderType = $orderTypeValue->value;
                    } elseif (is_string($orderTypeValue)) {
                        $orderType = $orderTypeValue;
                    }
                }
            } catch (\Exception $e) {
                // If enum access fails, set to null
                $orderType = null;
            }

            return [
                'items_count' => (int) ($receipt->items_count ?? 0),
                'order_type' => $orderType,
                'status' => $receipt->status ?? 'draft',
                'date' => $receipt->created_at?->format('Y-m-d H:i:s') ?? null,
            ];
        });

        return $paginator;
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

        // Transform results
        $paginator->getCollection()->transform(function ($receipt) {
            $orderType = null;
            try {
                if ($receipt->purchaseOrder && $receipt->purchaseOrder->order_type) {
                    $orderTypeValue = $receipt->purchaseOrder->order_type;
                    if ($orderTypeValue instanceof \BackedEnum) {
                        $orderType = $orderTypeValue->value;
                    } elseif (is_string($orderTypeValue)) {
                        $orderType = $orderTypeValue;
                    }
                }
            } catch (\Exception $e) {
                $orderType = null;
            }

            return [
                'id' => $receipt->id,
                'items_count' => (int) ($receipt->items_count ?? 0),
                'type' => $orderType,
                'status' => 'draft',
                'date' => $receipt->updated_at?->format('Y-m-d H:i:s') ?? $receipt->created_at?->format('Y-m-d H:i:s'),
            ];
        });

        return $paginator;
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

        // Transform results
        $paginator->getCollection()->transform(function ($receipt) {
            $orderType = null;
            try {
                if ($receipt->purchaseOrder && $receipt->purchaseOrder->order_type) {
                    $orderTypeValue = $receipt->purchaseOrder->order_type;
                    if ($orderTypeValue instanceof \BackedEnum) {
                        $orderType = $orderTypeValue->value;
                    } elseif (is_string($orderTypeValue)) {
                        $orderType = $orderTypeValue;
                    }
                }
            } catch (\Exception $e) {
                $orderType = null;
            }

            return [
                'id' => $receipt->id,
                'items_count' => (int) ($receipt->items_count ?? 0),
                'type' => $orderType,
                'status' => $receipt->status,
                'date' => $receipt->created_at?->format('Y-m-d H:i:s'),
            ];
        });

        return $paginator;
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

        // Transform results
        $paginator->getCollection()->transform(function ($receipt) {
            $orderType = null;
            $status = 'closed';

            try {
                if ($receipt->purchaseOrder) {
                    if ($receipt->purchaseOrder->order_type) {
                        $orderTypeValue = $receipt->purchaseOrder->order_type;
                        if ($orderTypeValue instanceof \BackedEnum) {
                            $orderType = $orderTypeValue->value;
                        } elseif (is_string($orderTypeValue)) {
                            $orderType = $orderTypeValue;
                        }
                    }

                    // Determine status
                    if (
                        $receipt->purchaseOrder->status === \Modules\Purchase\Enums\OrderStatus::CANCELED ||
                        $receipt->purchaseOrder->status === \Modules\Purchase\Enums\OrderStatus::CANCELLED_BY_BRANCH ||
                        $receipt->purchaseOrder->status === \Modules\Purchase\Enums\OrderStatus::CANCELLED_BY_SUPPLIER
                    ) {
                        $status = 'canceled';
                    }
                }
            } catch (\Exception $e) {
                $orderType = null;
            }

            return [
                'id' => $receipt->id,
                'items_count' => (int) ($receipt->items_count ?? 0),
                'type' => $orderType,
                'status' => $status,
                'date' => $receipt->created_at?->format('Y-m-d H:i:s'),
            ];
        });

        return $paginator;
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
     * @return GoodsReceipt
     */
    public function startReceiving(
        PurchaseOrder $order,
        string $receivedBy,
        array $itemsData = [],
        ?string $documentType = null,
        ?array $documentData = null,
        ?array $varianceData = null
    ): GoodsReceipt {
        return DB::transaction(function () use ($order, $receivedBy, $itemsData, $documentType, $documentData, $varianceData) {
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
            }

            // Calculate receipt summary
            $receipt->calculateSummary();

            // Refresh receipt to get updated items with variances
            $receipt->refresh();
            $receipt->load('items');

            // Create variance records for items with discrepancies
            $variancesMap = [];
            foreach ($receipt->items as $item) {
                if ($item->has_variance) {
                    // Check if variance already exists
                    $variance = $item->variance;
                    if (!$variance) {
                        $variance = $this->varianceService->createVariance($receipt, $item);
                    }
                    $variancesMap[$item->purchase_order_item_id] = $variance;
                }
            }

            // Process variance action if provided (applies to all items with variance)
            if (!empty($varianceData) && !empty($variancesMap)) {
                $action = $varianceData['action'];
                $varianceNote = $varianceData['note'] ?? null;
                $variancePhoto = $varianceData['photo'] ?? null;

                // Apply action to all variances
                foreach ($variancesMap as $variance) {
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
                }
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

            // Close order if no variances
            if (!$receipt->hasVariances) {
                $receipt->purchaseOrder->close();
            }

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
        $deliveryDetails = [
            'driver_name' => $receipt?->driver_name ?? $order->driver_name,
            'contact_number' => $receipt?->driver_contact ?? $order->driver_contact,
            'vehicle_number' => $receipt?->vehicle_number ?? $order->vehicle_number,
            'arrival_time' => $receipt?->arrival_time?->format('Y-m-d H:i:s')
                ?? $order->actual_delivery_at?->format('Y-m-d H:i:s'),
            'delivery_address' => $receipt?->delivery_address ?? $order->branch?->location,
        ];

        // If receipt exists, use receipt items, otherwise use order items
        if ($receipt && $receipt->items->isNotEmpty()) {
            $receipt->load(['items.variance']);
            $inspectionItems = $receipt->items->map(function ($item) {
                return $this->formatInspectionItem($item);
            });
        } else {
            // Use order items as fallback (not inspected yet)
            $inspectionItems = $order->items->map(function ($orderItem) {
                // Use quantity_confirmed if available (for partial confirmation), otherwise use quantity_ordered
                $expectedQuantity = $orderItem->quantity_confirmed ?? $orderItem->quantity_ordered;
                
                return [
                    'item_id' => $orderItem->id,
                    'product_name' => $orderItem->item_name,
                    'item_logo' => $orderItem->item_logo_url ?? null,
                    'qty_ordered' => (float) $expectedQuantity,
                    'qty_received' => 0.0, // Not inspected yet
                    'unit' => $orderItem->unit_of_measurement,
                    'quality' => 'normal', // Default until inspected
                    'price' => (float) $orderItem->unit_price,
                    'temperature' => null,
                    'expiration_date' => null,
                    'photo' => null,
                    'note' => null,
                    'variance' => null,
                ];
            });
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
            $order->transitionTo(OrderStatus::CLOSED);

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
            'purchaseOrder.items',
            'invoice',
            'variances',
            'documents',
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
        $order = PurchaseOrder::with([
            'items',
            'goodsReceipts.items',
            'goodsReceipts.invoice',
            'goodsReceipts.variances',
            'documents',
            'timelines',
            'supplier',
        ])->find($orderId);

        if (!$order) {
            return [];
        }

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
                        'requested_quantity' => (float) $item->quantity_ordered,
                        'status' => 'Preparing',
                        'quality_certificate' => $item->documents()
                            ->where('type', DocumentType::QUALITY_CERTIFICATE)
                            ->first()?->file_url,
                    ];
                }),
            ];
        }

        // Stage 2: Out for Delivery
        if ($order->status === \Modules\Purchase\Enums\OrderStatus::ON_THE_WAY) {
            $stages['out_for_delivery'] = [
                'status' => 'out_for_delivery',
                'arrival_time' => $order->expected_delivery_at?->format('Y-m-d H:i:s'),
                'driver_details' => [
                    'name' => $order->driver_name,
                    'photo' => $order->driver_photo,
                    'vehicle_number' => $order->vehicle_number,
                ],
                'delivery_address' => $order->branch->location ?? null,
                'delivery_notes' => $latestReceipt?->delivery_notes,
            ];
        }

        // Stage 3: Delivered
        if ($order->status === \Modules\Purchase\Enums\OrderStatus::DELIVERED && $latestReceipt) {
            $stages['delivered'] = [
                'status' => 'delivered',
                'invoice_file' => $latestReceipt->invoice?->file_url,
            ];
        }

        // Stage 4: Order Confirmation
        if ($latestReceipt && $latestReceipt->is_completed) {
            $stages['order_confirmation'] = [
                'status' => 'confirmed',
                'receipt_details' => [
                    'date_time' => $latestReceipt->inspection_completed_at?->format('Y-m-d H:i:s'),
                    'inspection_summary' => [
                        'number_of_items' => $latestReceipt->total_items_received,
                        'quantity_variance' => $latestReceipt->quantity_variances,
                        'total_amount' => (float) $latestReceipt->received_amount,
                        'driver_name' => $latestReceipt->driver_name,
                        'contact_number' => $latestReceipt->driver_contact,
                        'vehicle_number' => $latestReceipt->vehicle_number,
                        'arrival_time' => $latestReceipt->arrival_time?->format('Y-m-d H:i:s'),
                    ],
                    'goods_inspections' => $latestReceipt->items->map(function ($item) {
                        return [
                            'item_name' => $item->item_name,
                            'item_logo' => $item->item_logo_url,
                            'quantity_ordered' => (float) $item->quantity_ordered,
                            'quantity_received' => (float) $item->quantity_received,
                            'quality' => $item->quality_received?->value,
                            'variance_type' => $item->variance_type?->value,
                            'amount_variance' => $item->variance_amount ? (float) $item->variance_amount : null,
                            'temperature' => $item->temperature,
                            'expiration_date' => $item->expiry_date?->format('Y-m-d'),
                            'item_image' => $item->photo_url,
                            'additional_note' => $item->notes,
                        ];
                    }),
                    'document_type' => $latestReceipt->document_type?->value,
                    'financial_summary' => $latestReceipt->invoice ? [
                        'invoice_number' => $latestReceipt->invoice->invoice_number,
                        'invoice_date' => $latestReceipt->invoice->invoice_date?->format('Y-m-d'),
                        'supplier_name' => $order->supplier?->name,
                        'amount_before_tax' => (float) $latestReceipt->invoice->amount_before_tax,
                        'vat' => (float) $latestReceipt->invoice->tax_amount,
                        'total_amount' => (float) $latestReceipt->invoice->total_amount,
                    ] : null,
                ],
            ];
        }

        // Stage 5: Variance Logged
        if ($latestReceipt && $latestReceipt->hasVariances) {
            $stages['variance_logged'] = [
                'status' => 'variance_logged',
                'variances' => $latestReceipt->variances->map(function ($variance) {
                    return [
                        'item_name' => $variance->item_name,
                        'item_logo' => $variance->item_logo,
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
                }),
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
        $orderItems = $originalOrder->items()
            ->whereIn('id', collect($items)->pluck('item_id')->toArray())
            ->get();

        if ($orderItems->isEmpty()) {
            return $compensatory;
        }

        // Prepare items data for new order
        $newOrderItems = $orderItems->map(function ($orderItem) {
            return [
                'item_id' => $orderItem->item_id,
                'item_name' => $orderItem->item_name,
                'item_logo' => $orderItem->item_logo,
                'item_sku' => $orderItem->item_sku,
                'category' => $orderItem->category,
                'subcategory' => $orderItem->subcategory,
                'quantity_ordered' => $orderItem->quantity_ordered,
                'unit_of_measurement' => $orderItem->unit_of_measurement,
                'unit_price' => $orderItem->unit_price,
                'quality_ordered' => $orderItem->quality_ordered?->value,
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
        $compensatory->update([
            'new_order_id' => $newOrder->id,
            'reorder_supplier_id' => $originalOrder->supplier_id,
            'status' => 'ordered',
        ]);

        return $compensatory;
    }
}
