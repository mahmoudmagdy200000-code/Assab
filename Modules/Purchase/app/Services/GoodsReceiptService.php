<?php

namespace Modules\Purchase\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\VarianceType;
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
        private readonly CalculationService $calculationService
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
     * Start receiving an order
     */
    public function startReceiving(PurchaseOrder $order, string $receivedBy): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $receivedBy) {
            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'received_by' => $receivedBy,
                'status' => 'in_progress',
                'total_items_expected' => $order->items->count(),
                'expected_amount' => $order->total_amount,
                'inspection_started_at' => now(),
            ]);

            // Create receipt items from order items
            foreach ($order->items as $item) {
                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $item->id,
                    'item_id' => $item->item_id,
                    'item_name' => $item->item_name,
                    'item_logo' => $item->item_logo,
                    'unit_of_measurement' => $item->unit_of_measurement,
                    'quantity_ordered' => $item->quantity_ordered,
                    'quantity_received' => 0,
                    'quality_ordered' => $item->quality_ordered,
                    'unit_price' => $item->unit_price,
                    'expected_total' => $item->total_price,
                    'received_total' => 0,
                ]);
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

            return $receipt->fresh(['items', 'purchaseOrder']);
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
        $amountBeforeTax = $data['amount_before_tax'] ?? $receipt->received_amount;
        $taxRate = $data['tax_rate'] ?? 15.00;
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

        // Handle file upload
        if (!empty($data['file'])) {
            OrderDocument::upload($invoice, $data['file'], DocumentType::INVOICE, 'Invoice');
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
                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $orderItem->id,
                    'item_id' => $orderItem->item_id,
                    'item_name' => $orderItem->item_name,
                    'item_logo' => $orderItem->item_logo,
                    'unit_of_measurement' => $orderItem->unit_of_measurement,
                    'quantity_ordered' => $orderItem->quantity_ordered, // Original Quantity
                    'quantity_received' => $orderItem->quantity_confirmed ?? $orderItem->quantity_ordered, // New Quantity
                    'quality_ordered' => $orderItem->quality_ordered,
                    'unit_price' => $orderItem->unit_price,
                    'expected_total' => $orderItem->total_price,
                    'received_total' => ($orderItem->quantity_confirmed ?? $orderItem->quantity_ordered) * $orderItem->unit_price,
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
}
