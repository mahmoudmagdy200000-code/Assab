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
        $paginator = GoodsReceipt::select(['id', 'status', 'created_at', 'purchase_order_id'])
            ->with(['purchaseOrder:id,order_type'])
            ->withCount('items as items_count')
            ->byBranch($branchId)
            ->whereIn('status', ['draft', 'in_progress'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Transform results to return only requested fields
        $paginator->getCollection()->transform(function ($receipt) {
            return [
                'items_count' => $receipt->items_count ?? 0,
                'order_type' => $receipt->purchaseOrder?->order_type?->value ?? null,
                'status' => $receipt->status,
                'date' => $receipt->created_at?->format('Y-m-d H:i:s'),
            ];
        });

        return $paginator;
    }

    /**
     * Get draft receipts
     */
    public function getDraftReceipts(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        return GoodsReceipt::with(['purchaseOrder.items', 'purchaseOrder.supplier'])
            ->byBranch($branchId)
            ->draft()
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get receipts with missing goods (variances)
     */
    public function getMissingGoodsReceipts(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        return GoodsReceipt::with(['purchaseOrder.supplier', 'variances', 'items'])
            ->byBranch($branchId)
            ->withVariances()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get completed receipts
     */
    public function getCompletedReceipts(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        return GoodsReceipt::with(['purchaseOrder.supplier', 'invoice', 'items'])
            ->byBranch($branchId)
            ->completed()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
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
}
