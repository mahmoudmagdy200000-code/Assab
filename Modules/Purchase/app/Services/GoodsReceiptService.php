<?php

namespace Modules\Purchase\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\InternalTransferVariance;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
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
        return $paginator->through(fn ($receipt) => new GoodsReceiptListResource($receipt));
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
        return $paginator->through(fn ($receipt) => new GoodsReceiptListResource($receipt));
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
        return $paginator->through(fn ($receipt) => new GoodsReceiptListResource($receipt));
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
        return $paginator->through(fn ($receipt) => new GoodsReceiptListResource($receipt));
    }

    /**
     * Start receiving an order with items inspection data, document type, and variance actions
     *
     * @param  array  $itemsData  Array of items with inspection details: item_id, quantity_received, quality, temperature, expiration_date, photo, notes
     * @param  string|null  $documentType  Optional: invoice, delivery_note, receipt_without_document
     * @param  array|null  $documentData  Optional: Data for document type (invoice_data or delivery_note_data)
     * @param  array|null  $varianceData  Optional: Single variance action object for all items with variance (action, note, photo, compensatory_order_data, deduct_data)
     * @param  array  $unlistedItems  Optional: Unlisted items (gifts from supplier)
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
            $order = PurchaseOrder::withoutGlobalScopes()
                ->with(['items.item', 'branch'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $order->can_receive) {
                throw new AsabException(
                    'ORDER_NOT_RECEIVABLE',
                    'Order cannot be received in current status.',
                    'لا يمكن استلام الطلب في حالته الحالية',
                    409
                );
            }

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
                if ($itemsData !== [] && ! $receivedItemsMap->has($orderItem->id)) {
                    continue;
                }

                $receivedItemData = $receivedItemsMap->get($orderItem->id);

                // Get inspection data if provided, otherwise use defaults
                $quantityReceived = round((float) ($receivedItemData['quantity_received'] ?? 0), 3);
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
                $expectedQuantity = round((float) ($orderItem->quantity_confirmed ?? $orderItem->quantity_ordered), 3);
                $expectedTotal = $expectedQuantity * $orderItem->unit_price;

                // DEC-05 + CORRECTION-03: split actual received into approved-available vs reserved excess
                $priorPosted = round((float) GoodsReceiptItem::where('purchase_order_item_id', $orderItem->id)
                    ->sum('quantity_available_posted'), 3);
                $remainingOrdered = max(0.0, round($expectedQuantity - $priorPosted, 3));
                $availablePosted = min($quantityReceived, $remainingOrdered);
                $excessReserved = max(0.0, round($quantityReceived - $availablePosted, 3));

                $receiptItem = GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $orderItem->id,
                    'item_id' => $orderItem->item_id,
                    'item_name' => $orderItem->item_name,
                    'item_logo' => $orderItem->item_logo,
                    'unit_of_measurement' => $orderItem->unit_of_measurement,
                    'quantity_ordered' => $expectedQuantity, // Use confirmed quantity if available
                    'quantity_received' => $quantityReceived,
                    'quantity_available_posted' => $availablePosted,
                    'quantity_excess_reserved' => $excessReserved,
                    'excess_status' => $excessReserved > 0.0 ? 'reserved_pending_approval' : null,
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

                // T1.2: post only approved/available quantity to BranchInventory, BranchItem & InventoryStockMovement
                $this->postSupplierReceivingStock($order, $receipt, $receiptItem, $orderItem, $availablePosted, $excessReserved, $receivedBy);

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
            if (! empty($varianceData)) {
                // If compensatory_order, create variances only for items in variance.items
                if ($varianceData['action'] === 'compensatory_order' && ! empty($varianceData['items'])) {
                    $varianceItemIds = collect($varianceData['items'])->pluck('item_id')->toArray();
                    foreach ($receipt->items as $item) {
                        if (in_array($item->purchase_order_item_id, $varianceItemIds)) {
                            // Check if variance already exists
                            $variance = $item->variance;
                            if (! $variance) {
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
                            if (! $variance) {
                                $variance = $this->varianceService->createVariance($receipt, $item);
                            }
                            $variancesMap[$item->purchase_order_item_id] = $variance;
                        }
                    }
                }
            }

            // Handle document type FIRST so createInvoice() exists before deduct_from_invoice runs (PUR-F05).
            if ($documentType) {
                $docType = DocumentType::from($documentType);
                $receipt->setDocumentType($docType);

                match ($documentType) {
                    'invoice' => $this->createInvoice($receipt, $documentData ?? []),
                    'delivery_note' => $this->createDeliveryNote($receipt, $documentData ?? []),
                    'receipt_without_document' => $this->createReceiptWithoutDocument($receipt),
                };

                $receipt->refresh();
            }

            // Process variance action if provided (applies to all items with variance).
            // CORRECTION-04 (PUR-F13): never swallow variance processing exceptions;
            // any failure must abort and roll back the entire receiving transaction.
            if (! empty($varianceData) && ! empty($variancesMap)) {
                $action = $varianceData['action'];
                $varianceNote = $varianceData['note'] ?? null;
                $variancePhoto = $varianceData['photo'] ?? null;

                if ($action === 'deduct_from_invoice') {
                    // AUD-S1-002: deduct_data.amount is a receipt-level aggregate; allocate
                    // it proportionally across variance lines once while preserving exact sum.
                    $requestedDeduction = round((float) ($varianceData['deduct_data']['amount'] ?? 0), 2);
                    if ($requestedDeduction <= 0.0) {
                        throw new \InvalidArgumentException('Deduction amount must be greater than 0.');
                    }

                    $deductReason = $varianceData['deduct_data']['reason'] ?? 'short_quantity';
                    $deductNotes = $varianceData['deduct_data']['notes'] ?? $varianceNote;
                    $varianceList = array_values($variancesMap);
                    $totalHalalas = (int) round($requestedDeduction * 100);
                    $weights = array_map(
                        fn (PurchaseVariance $v) => max(0.0, (float) $v->variance_amount),
                        $varianceList
                    );
                    $weightSum = array_sum($weights);
                    $lineCount = count($varianceList);
                    $allocatedHalalas = 0;

                    foreach ($varianceList as $idx => $variance) {
                        if ($idx === $lineCount - 1) {
                            $lineHalalas = $totalHalalas - $allocatedHalalas;
                        } elseif ($weightSum > 0.0) {
                            $lineHalalas = (int) round($totalHalalas * ($weights[$idx] / $weightSum));
                        } else {
                            $lineHalalas = intdiv($totalHalalas, $lineCount);
                        }
                        $allocatedHalalas += $lineHalalas;
                        $lineAmount = round($lineHalalas / 100, 2);

                        if ($lineAmount > 0.0) {
                            $this->varianceService->deductFromInvoice(
                                $variance,
                                $lineAmount,
                                $deductReason,
                                $deductNotes
                            );
                        } else {
                            $variance->deductFromInvoice(0.0, $deductReason, $deductNotes);
                        }

                        if (! empty($variancePhoto)) {
                            $this->varianceService->addPhotoEvidence($variance, [$variancePhoto]);
                        }
                    }
                } else {
                    foreach ($variancesMap as $variance) {
                        match ($action) {
                            'accept' => $this->varianceService->acceptVariance($variance),
                            'compensatory_order' => $this->handleCompensatoryOrder(
                                $variance,
                                $order,
                                $varianceData['items'] ?? [],
                                $varianceNote,
                                $variancePhoto
                            ),
                        };

                        if ($action === 'accept' && ! empty($variancePhoto)) {
                            $this->varianceService->addPhotoEvidence($variance, [$variancePhoto]);
                        }
                    }
                }
            }

            // Log variance creation for debugging
            if (! empty($variancesMap)) {
                \Log::info('GoodsReceipt: Variances created', [
                    'receipt_id' => $receipt->id,
                    'variances_count' => count($variancesMap),
                    'variance_ids' => collect($variancesMap)->pluck('id')->toArray(),
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
            $this->timelineService->logReceivingStarted($order);

            // Save delivered stage if invoice was created
            $receipt->refresh();
            if ($receipt->invoice || $documentType === 'invoice') {
                $this->trackingService->saveDeliveredStage($order);
            }

            // Check if receipt should be completed and whether the PO has received all quantities
            $receipt->refresh();
            $receipt->load('items');

            $this->syncOrderItemReceivedQuantities($receipt);

            if (! $receipt->hasVariances) {
                $receipt->completeInspection();
                $receipt->refresh();
                $this->timelineService->logInspectionCompleted($receipt);

                $order->refresh();
                $order->load('latestGoodsReceipt');
                $this->trackingService->saveOrderConfirmationStage($order);

                if ($this->canCloseOrderAfterReceiving($order)) {
                    $order->close();
                }
            }

            return $receipt->fresh(['items', 'purchaseOrder', 'variances', 'invoice']);
        }, 3);
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
        $qty = round((float) ($data['quantity'] ?? 0), 3);

        return GoodsReceiptItem::create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $data['item_id'] ?? null,
            'item_name' => $data['item_name'],
            'item_logo' => $data['item_logo'] ?? null,
            'unit_of_measurement' => $data['unit'] ?? 'kg',
            'quantity_ordered' => 0,
            'quantity_received' => $qty,
            'quantity_available_posted' => 0,
            'quantity_excess_reserved' => $qty,
            'excess_status' => $qty > 0.0 ? 'reserved_pending_approval' : null,
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
     * Create or update (complete/correct) invoice for an existing receipt without duplicating stock movements (T1.4 / REG-DEC-06).
     */
    public function createInvoice(GoodsReceipt $receipt, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($receipt, $data) {
            $receipt = GoodsReceipt::with('purchaseOrder')->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            // Calculate amounts from receipt or provided data
            $amountBeforeTax = round(
                isset($data['amount_before_tax']) ? (float) $data['amount_before_tax'] : (float) $receipt->received_amount,
                2
            );

            if (! array_key_exists('tax_rate', $data) && ! array_key_exists('vat_rate', $data)) {
                throw new \InvalidArgumentException('Invoice tax rate is required and cannot be defaulted.');
            }

            if (
                array_key_exists('tax_rate', $data)
                && array_key_exists('vat_rate', $data)
                && $data['tax_rate'] !== null && $data['tax_rate'] !== ''
                && $data['vat_rate'] !== null && $data['vat_rate'] !== ''
                && (float) $data['tax_rate'] !== (float) $data['vat_rate']
            ) {
                throw new \InvalidArgumentException('Submitted tax_rate and vat_rate do not match.');
            }

            $rawRate = $data['tax_rate'] ?? $data['vat_rate'];
            if ($rawRate === null || $rawRate === '') {
                throw new \InvalidArgumentException('Invoice tax rate is required and cannot be defaulted.');
            }
            $taxRate = (float) $rawRate;
            if (! in_array($taxRate, [0.0, 15.0], true)) {
                throw new \InvalidArgumentException('Invoice tax rate must be 0% or 15%.');
            }

            // AUD-S1-003: derive authoritative VAT and total; reject contradictory client overrides.
            $expectedTax = round($this->calculationService->calculateVAT($amountBeforeTax, $taxRate), 2);
            $expectedTotal = round($amountBeforeTax + $expectedTax, 2);

            $hasTaxAmount = array_key_exists('tax_amount', $data) && $data['tax_amount'] !== null && $data['tax_amount'] !== '';
            $hasVatAmount = array_key_exists('vat_amount', $data) && $data['vat_amount'] !== null && $data['vat_amount'] !== '';

            if ($hasTaxAmount && $hasVatAmount && abs((float) $data['tax_amount'] - (float) $data['vat_amount']) > 0.01) {
                throw new \InvalidArgumentException('Submitted tax_amount and vat_amount do not match.');
            }

            if ($hasTaxAmount || $hasVatAmount) {
                $submittedTax = round((float) ($hasTaxAmount ? $data['tax_amount'] : $data['vat_amount']), 2);
                if (abs($submittedTax - $expectedTax) > 0.01) {
                    throw new \InvalidArgumentException("Submitted VAT amount ({$submittedTax}) does not match explicit {$taxRate}% VAT ({$expectedTax}).");
                }
            }

            if (array_key_exists('total_amount', $data) && $data['total_amount'] !== null && $data['total_amount'] !== '') {
                $submittedTotal = round((float) $data['total_amount'], 2);
                if (abs($submittedTotal - $expectedTotal) > 0.01) {
                    throw new \InvalidArgumentException("Submitted invoice total ({$submittedTotal}) does not match authoritative total ({$expectedTotal}).");
                }
            }

            $taxAmount = $expectedTax;
            $totalAmount = $expectedTotal;

            if ($receipt->document_type !== DocumentType::INVOICE) {
                $receipt->setDocumentType(DocumentType::INVOICE);
            }

            $existingInvoice = PurchaseInvoice::where('goods_receipt_id', $receipt->id)->lockForUpdate()->first();

            $priceApprovals = app(ReceiptPriceApprovalService::class);
            $previousPriceFacts = $existingInvoice ? $priceApprovals->invoiceFacts($existingInvoice) : null;

            if ($existingInvoice) {
                if ($existingInvoice->status === 'paid') {
                    throw new AsabException(
                        'INVOICE_ALREADY_PAID',
                        'Cannot replace a paid invoice.',
                        'لا يمكن تعديل فاتورة مدفوعة',
                        409
                    );
                }

                $deduction = round((float) ($existingInvoice->deduction_amount ?? 0), 2);
                if ($deduction > $totalAmount + 0.001) {
                    throw new \InvalidArgumentException('Corrected invoice total cannot be lower than existing deductions.');
                }

                $updatePayload = [
                    'invoice_number' => $data['invoice_number'] ?? $existingInvoice->invoice_number,
                    'invoice_date' => $data['invoice_date'] ?? $existingInvoice->invoice_date,
                    'due_date' => $data['due_date'] ?? $this->calculationService->calculateDueDate($data['invoice_date'] ?? $existingInvoice->invoice_date),
                    'payment_terms' => $data['payment_terms'] ?? $existingInvoice->payment_terms,
                    'amount_before_tax' => $amountBeforeTax,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'total_amount' => $totalAmount,
                    'final_amount' => round(max(0.0, $totalAmount - $deduction), 2),
                    'status' => 'pending',
                ];

                foreach (['file_path', 'file_name', 'file_type', 'file_size'] as $fileKey) {
                    if (! empty($data[$fileKey])) {
                        $updatePayload[$fileKey] = $data[$fileKey];
                    }
                }

                $existingInvoice->update($updatePayload);
                $invoice = $existingInvoice->fresh();
            } else {
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
                    'file_path' => $data['file_path'] ?? ($data['photo'] ?? null),
                    'file_name' => $data['file_name'] ?? (! empty($data['photo']) ? basename((string) $data['photo']) : null),
                    'file_type' => $data['file_type'] ?? null,
                    'file_size' => $data['file_size'] ?? null,
                    'status' => 'pending',
                ]);
            }

            // Handle photo/file document attachment
            $docPath = $data['photo'] ?? ($data['file_path'] ?? null);
            if (! empty($docPath)) {
                OrderDocument::create([
                    'documentable_type' => get_class($invoice),
                    'documentable_id' => $invoice->id,
                    'type' => DocumentType::INVOICE,
                    'file_path' => $docPath,
                    'file_name' => $data['file_name'] ?? basename((string) $docPath),
                    'original_name' => $data['file_name'] ?? basename((string) $docPath),
                    'mime_type' => $data['file_type'] ?? 'image/jpeg',
                    'file_size' => (int) ($data['file_size'] ?? 0),
                    'title' => 'Invoice Photo',
                    'description' => $data['notes'] ?? null,
                    'uploaded_by' => \Illuminate\Support\Facades\Auth::id(),
                    'uploaded_by_type' => \Illuminate\Support\Facades\Auth::user() ? get_class(\Illuminate\Support\Facades\Auth::user()) : null,
                    'is_active' => true,
                ]);
            }

            if ($previousPriceFacts !== $priceApprovals->invoiceFacts($invoice)) {
                $meta = $receipt->cost_adjustment_meta ?? [];
                $meta['invoice_price_revision'] = (int) ($meta['invoice_price_revision'] ?? 0) + 1;
                $receipt->update(['cost_adjustment_meta' => $meta]);
            }
            $this->reconcileInvoiceCostAdjustment($receipt, $invoice);

            $this->timelineService->logInvoiceUploaded($receipt, $invoice);
            if ($receipt->purchaseOrder) {
                $this->trackingService->saveDeliveredStage($receipt->purchaseOrder);
            }

            return $invoice;
        });
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

            // Update order item quantities cumulatively across all receipts
            $this->syncOrderItemReceivedQuantities($receipt);

            $this->timelineService->logInspectionCompleted($receipt);

            // Save tracking stages
            $order = $receipt->purchaseOrder;
            $this->trackingService->saveOrderConfirmationStage($order);

            if ($receipt->hasVariances) {
                $this->trackingService->saveVarianceLoggedStage($order);
            }

            // Close order ONLY when all quantities are received and no variances/excess remain unresolved
            if (! $receipt->hasVariances && $this->canCloseOrderAfterReceiving($order->fresh('items'))) {
                $order->close();
            }

            return $receipt->fresh(['items', 'variances', 'invoice']);
        });
    }

    private function syncOrderItemReceivedQuantities(GoodsReceipt $receipt): void
    {
        foreach ($receipt->items as $item) {
            if (! $item->purchase_order_item_id || ! $item->purchaseOrderItem) {
                continue;
            }

            $cumulativeReceived = round((float) GoodsReceiptItem::where('purchase_order_item_id', $item->purchase_order_item_id)
                ->sum('quantity_received'), 3);

            $item->purchaseOrderItem->markAsReceived(
                $cumulativeReceived,
                $item->quality_received?->value
            );
        }
    }

    private function canCloseOrderAfterReceiving(PurchaseOrder $order): bool
    {
        $order->loadMissing('items');
        $cancelledStatuses = OrderItemStatus::cancelledStatusValues();

        foreach ($order->items as $orderItem) {
            $statusVal = $orderItem->status instanceof OrderItemStatus
                ? $orderItem->status->value
                : (string) ($orderItem->status ?? '');

            if (in_array($statusVal, array_merge($cancelledStatuses, [OrderItemStatus::REJECTED->value]), true)) {
                continue;
            }

            $expected = round((float) ($orderItem->quantity_confirmed ?? $orderItem->quantity_ordered), 3);
            $cumulative = round((float) GoodsReceiptItem::where('purchase_order_item_id', $orderItem->id)
                ->sum('quantity_received'), 3);

            if ($cumulative + 0.0005 < $expected) {
                return false;
            }

            if ($cumulative > $expected + 0.0005) {
                return false;
            }
        }

        $hasOpenVariances = PurchaseVariance::where('purchase_order_id', $order->id)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->exists();

        if ($hasOpenVariances) {
            return false;
        }

        $hasPendingExcess = GoodsReceiptItem::whereHas('goodsReceipt', fn ($q) => $q->where('purchase_order_id', $order->id))
            ->where('excess_status', 'reserved_pending_approval')
            ->where('quantity_excess_reserved', '>', 0)
            ->exists();

        return ! $hasPendingExcess;
    }

    private function postSupplierReceivingStock(
        PurchaseOrder $order,
        GoodsReceipt $receipt,
        GoodsReceiptItem $receiptItem,
        \Modules\Purchase\Models\PurchaseOrderItem $orderItem,
        float $availablePosted,
        float $excessReserved,
        string $receivedBy
    ): void {
        if ($availablePosted <= 0.0 || empty($orderItem->item_id)) {
            return;
        }

        $itemId = (string) $orderItem->item_id;
        $now = now();
        $idemKey = 'purchase_receipt:'.$receiptItem->id;

        if (InventoryStockMovement::where('idempotency_key', $idemKey)->exists()) {
            return;
        }

        $branchInv = BranchInventory::where('branch_id', $order->branch_id)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();

        if ($branchInv) {
            $branchInv->update([
                'available_quantity' => max(0.0, round((float) $branchInv->available_quantity + $availablePosted, 3)),
                'last_inventory_update' => $now,
            ]);
        } else {
            BranchInventory::create([
                'branch_id' => $order->branch_id,
                'item_id' => $itemId,
                'available_quantity' => $availablePosted,
                'reserved_quantity' => 0,
                'last_inventory_update' => $now,
            ]);
        }

        $branchItem = BranchItem::where('branch_id', $order->branch_id)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();
        if ($branchItem) {
            $branchItem->update([
                'quantity' => max(0.0, round((float) $branchItem->quantity + $availablePosted, 3)),
            ]);
        }

        $companyId = (string) ($order->branch?->asab_company_id ?? Branch::whereKey($order->branch_id)->value('asab_company_id') ?? '');
        $unitCostHalalas = (int) round(((float) $orderItem->unit_price) * 100);
        $totalCostHalalas = (int) round($availablePosted * $unitCostHalalas);

        InventoryStockMovement::create([
            'idempotency_key' => $idemKey,
            'company_id' => $companyId,
            'branch_id' => $order->branch_id,
            'item_id' => $itemId,
            'movement_type' => 'purchase_in',
            'qty_delta' => $availablePosted,
            'unit_cost_halalas' => $unitCostHalalas,
            'total_cost_halalas' => $totalCostHalalas,
            'source_type' => 'goods_receipt',
            'source_id' => $receipt->id,
            'revision' => 1,
            'cost_source' => 'PO_UNIT_PRICE_PROVISIONAL',
            'posted_at' => $now,
            'created_by_id' => $receivedBy,
            'notes' => 'استلام مشتريات مورد — '.$order->order_number,
            'meta' => [
                'goods_receipt_item_id' => $receiptItem->id,
                'purchase_order_id' => $order->id,
                'purchase_order_item_id' => $orderItem->id,
                'excess_reserved_qty' => $excessReserved,
            ],
        ]);

        if ($companyId !== '' && $totalCostHalalas > 0) {
            $yearMonth = ($receipt->created_at ?? $now)->format('Y-m');
            $monthlyResult = app(\Modules\Admin\Services\MonthlyWeightedCostService::class)
                ->recordPurchaseCostDelta(
                    $companyId,
                    (string) $order->branch_id,
                    $itemId,
                    $yearMonth,
                    $availablePosted,
                    $totalCostHalalas
                );

            if ($monthlyResult['status'] === 'pending_period_decision') {
                $existingMeta = is_array($receipt->cost_adjustment_meta) ? $receipt->cost_adjustment_meta : [];
                $existingMeta['closed_period_items'][] = [
                    'item_id' => $itemId,
                    'year_month' => $yearMonth,
                    'qty' => $availablePosted,
                    'delta_halalas' => $totalCostHalalas,
                ];
                $receipt->update([
                    'cost_adjustment_status' => 'pending_period_decision',
                    'pending_cost_delta_halalas' => (int) ($receipt->pending_cost_delta_halalas ?? 0) + $totalCostHalalas,
                    'cost_adjustment_meta' => $existingMeta,
                ]);
            }
        }
    }

    /**
     * Post value-only cost adjustments (qty_delta = 0) when a pre-VAT supplier invoice amount
     * differs from the provisional PO cost posted at receiving (DEC-07). If the receipt's period
     * is already APPROVED/closed, preserve it untouched and flag pending_period_decision (DEC-OPEN-02).
     */
    public function reconcileApprovedInvoice(GoodsReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt) {
            $locked = GoodsReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $invoice = PurchaseInvoice::where('goods_receipt_id', $locked->id)->lockForUpdate()->first();
            if ($invoice) {
                $this->reconcileInvoiceCostAdjustment($locked, $invoice);
            }
        });
    }
    private function reconcileInvoiceCostAdjustment(GoodsReceipt $receipt, PurchaseInvoice $invoice): void
    {
        $receipt->loadMissing(['items', 'purchaseOrder.branch']);
        $order = $receipt->purchaseOrder;
        if (! $order) {
            return;
        }

        $companyId = (string) ($order->branch?->asab_company_id ?? Branch::whereKey($order->branch_id)->value('asab_company_id') ?? '');
        if ($companyId === '') {
            return;
        }

        $allItems = $receipt->items
            ->filter(fn ($it) => (float) $it->quantity_received > 0)
            ->sortBy('id')
            ->values();

        if ($allItems->isEmpty()) {
            return;
        }

        // AUD-S1-005: allocate invoice value across ALL receipt lines (including reserved excess
        // and unlisted items) so reserved units never inflate approved available inventory cost.
        $authorizedPriceLines = app(ReceiptPriceApprovalService::class)->authorizedLines($receipt, $invoice);
        $previousApprovedTargets = app(ReceiptPriceApprovalService::class)->previousApprovedTargets($receipt, $invoice);
        $targetTotalHalalas = (int) round(((float) $invoice->amount_before_tax) * 100);
        $weights = $allItems->map(fn ($it) => max(0.01, ((float) $it->quantity_received) * max(0.01, (float) $it->unit_price)));
        $weightSum = (float) $weights->sum();

        $receiptMovements = InventoryStockMovement::where('source_type', 'goods_receipt')
            ->where('source_id', $receipt->id)
            ->whereIn('movement_type', ['purchase_in', 'purchase_cost_adjustment'])
            ->get(['id', 'item_id', 'movement_type', 'total_cost_halalas', 'revision', 'meta']);



        $yearMonth = ($receipt->created_at ?? now())->format('Y-m');
        $monthlyCostService = app(\Modules\Admin\Services\MonthlyWeightedCostService::class);
        $allocatedInvoiceTotal = 0;
        $pendingClosedDeltaTotal = 0;
        $reservedExcessTotal = 0;
        $pendingPriceDeltaTotal = 0;
        $closedMeta = [];
        $reservedMeta = [];
        $pendingPriceMeta = [];
        $appliedCount = 0;
        $now = now();
        $lastIdx = $allItems->count() - 1;

        foreach ($allItems as $idx => $receiptItem) {
            $itemInvoiceHalalas = ($idx === $lastIdx)
                ? ($targetTotalHalalas - $allocatedInvoiceTotal)
                : (int) round($targetTotalHalalas * ((float) $weights[$idx] / max(0.01, $weightSum)));
            $allocatedInvoiceTotal += $itemInvoiceHalalas;

            $availablePosted = max(0.0, (float) ($receiptItem->quantity_available_posted ?? $receiptItem->quantity_received));
            $excessReserved = max(0.0, (float) ($receiptItem->quantity_excess_reserved ?? 0.0));
            $hasPendingExcess = $receiptItem->excess_status === 'reserved_pending_approval' || $excessReserved > 0.0;

            if (! $hasPendingExcess) {
                $invoiceAvailableShare = $itemInvoiceHalalas;
                $itemReservedTarget = 0;
            } elseif ($availablePosted <= 0.0) {
                $invoiceAvailableShare = 0;
                $itemReservedTarget = $itemInvoiceHalalas;
            } else {
                $qtyDenominator = max(0.001, $availablePosted + $excessReserved);
                $invoiceAvailableShare = (int) round($itemInvoiceHalalas * ($availablePosted / $qtyDenominator));
                $itemReservedTarget = $itemInvoiceHalalas - $invoiceAvailableShare;
            }

            // Only the dedicated, authenticated and revision-bound procurement decision may release price differences.

            $approvedBaselineHalalas = (int) round($availablePosted * (float) $receiptItem->unit_price * 100);
            $approvedBaselineHalalas = (int) ($previousApprovedTargets[$receiptItem->id] ?? $approvedBaselineHalalas);
            $itemApprovedTarget = in_array((string) $receiptItem->id, $authorizedPriceLines, true)
                ? $invoiceAvailableShare : $approvedBaselineHalalas;
            $linePendingPriceDelta = $availablePosted > 0.0
                ? ($invoiceAvailableShare - $itemApprovedTarget)
                : 0;
            if ($linePendingPriceDelta !== 0) {
                $pendingPriceDeltaTotal += $linePendingPriceDelta;
                $pendingPriceMeta[] = [
                    'receipt_item_id' => $receiptItem->id,
                    'item_id' => $receiptItem->item_id,
                    'available_qty' => $availablePosted,
                    'approved_unit_price' => round((float) $receiptItem->unit_price, 2),
                    'approved_baseline_halalas' => $approvedBaselineHalalas,
                    'invoice_available_halalas' => $invoiceAvailableShare,
                    'pending_price_delta_halalas' => $linePendingPriceDelta,
                    'status' => 'pending_approval',
                ];
            }

            if ($itemReservedTarget !== 0) {
                $reservedExcessTotal += $itemReservedTarget;
                $reservedMeta[] = [
                    'receipt_item_id' => $receiptItem->id,
                    'item_id' => $receiptItem->item_id,
                    'excess_qty' => $excessReserved,
                    'reserved_value_halalas' => $itemReservedTarget,
                    'excess_status' => $receiptItem->excess_status ?? 'reserved_pending_approval',
                ];
            }

            if ($availablePosted <= 0.0 || empty($receiptItem->item_id)) {
                continue;
            }

            $itemId = (string) $receiptItem->item_id;
            $itemMovements = $receiptMovements->filter(
                fn ($m) => (string) ($m->meta['goods_receipt_item_id'] ?? '') === (string) $receiptItem->id
            );
            $itemPostedTotal = (int) $itemMovements->sum('total_cost_halalas');
            $itemDelta = $itemApprovedTarget - $itemPostedTotal;

            if ($itemDelta === 0) {
                continue;
            }

            // AUD-S1-004: use monotonic per-receipt-item transition revision so A->B->A->B
            // value revisions are never suppressed while replayed identical amounts hit delta=0 above.
            $nextRevision = max(2, ((int) $itemMovements->max('revision')) + 1);
            $idemKey = "purchase_cost_adj:{$receipt->id}:{$receiptItem->id}:rev:{$nextRevision}";

            if (InventoryStockMovement::where('idempotency_key', $idemKey)->exists()) {
                continue;
            }

            $monthlyResult = $monthlyCostService->recordPurchaseCostDelta(
                $companyId,
                (string) $order->branch_id,
                $itemId,
                $yearMonth,
                0.0,
                $itemDelta
            );

            if ($monthlyResult['status'] === 'pending_period_decision') {
                $pendingClosedDeltaTotal += $itemDelta;
                $closedMeta[] = [
                    'receipt_item_id' => $receiptItem->id,
                    'item_id' => $itemId,
                    'year_month' => $yearMonth,
                    'delta_halalas' => $itemDelta,
                    'invoice_id' => $invoice->id,
                ];

                continue;
            }

            InventoryStockMovement::create([
                'idempotency_key' => $idemKey,
                'company_id' => $companyId,
                'branch_id' => $order->branch_id,
                'item_id' => $itemId,
                'movement_type' => 'purchase_cost_adjustment',
                'qty_delta' => 0.0,
                'unit_cost_halalas' => 0,
                'total_cost_halalas' => $itemDelta,
                'source_type' => 'goods_receipt',
                'source_id' => $receipt->id,
                'revision' => $nextRevision,
                'cost_source' => 'INVOICE_VALUE_ADJUSTMENT',
                'posted_at' => $now,
                'created_by_id' => \Illuminate\Support\Facades\Auth::id() ?? $receipt->received_by,
                'notes' => 'تعديل قيمة تكلفة المشتريات بالفاتورة — '.$invoice->invoice_number,
                'meta' => [
                    'goods_receipt_item_id' => $receiptItem->id,
                    'purchase_invoice_id' => $invoice->id,
                    'target_pre_vat_halalas' => $targetTotalHalalas,
                    'approved_item_target_halalas' => $itemApprovedTarget,
                    'reserved_item_target_halalas' => $itemReservedTarget,
                ],
            ]);
            $appliedCount++;
        }

        $meta = is_array($receipt->cost_adjustment_meta) ? $receipt->cost_adjustment_meta : [];
        if ($reservedMeta !== []) {
            $meta['reserved_excess_items'] = $reservedMeta;
        } else {
            unset($meta['reserved_excess_items']);
        }
        if ($pendingPriceMeta !== []) {
            $meta['pending_price_deltas'] = $pendingPriceMeta;
        } else {
            unset($meta['pending_price_deltas']);
        }

        if ($closedMeta !== []) {
            $meta['pending_period_decision'] = $closedMeta;
            $receipt->update([
                'cost_adjustment_status' => 'pending_period_decision',
                'pending_cost_delta_halalas' => $pendingClosedDeltaTotal + $reservedExcessTotal + $pendingPriceDeltaTotal,
                'cost_adjustment_meta' => $meta,
            ]);
        } elseif ($reservedMeta !== [] && $pendingPriceMeta !== []) {
            $receipt->update([
                'cost_adjustment_status' => 'pending_excess_and_price_approval',
                'pending_cost_delta_halalas' => $reservedExcessTotal + $pendingPriceDeltaTotal,
                'cost_adjustment_meta' => $meta,
            ]);
        } elseif ($reservedMeta !== []) {
            $receipt->update([
                'cost_adjustment_status' => 'pending_excess_approval',
                'pending_cost_delta_halalas' => $reservedExcessTotal,
                'cost_adjustment_meta' => $meta,
            ]);
        } elseif ($pendingPriceMeta !== []) {
            $receipt->update([
                'cost_adjustment_status' => 'pending_price_approval',
                'pending_cost_delta_halalas' => $pendingPriceDeltaTotal,
                'cost_adjustment_meta' => $meta,
            ]);
        } elseif ($appliedCount > 0) {
            $receipt->update([
                'cost_adjustment_status' => 'adjusted',
                'pending_cost_delta_halalas' => 0,
                'cost_adjustment_meta' => $meta !== [] ? $meta : null,
            ]);
        } elseif ($receipt->cost_adjustment_status !== 'pending_period_decision') {
            $receipt->update([
                'cost_adjustment_status' => 'synced',
                'pending_cost_delta_halalas' => 0,
                'cost_adjustment_meta' => $meta !== [] ? $meta : null,
            ]);
        }
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
        if (! $receipt->is_draft) {
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
        if (! empty($data['file'])) {
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

        if (! $order) {
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

        // PUR-05 / BM-16: return actual invoice financial values when an invoice already exists.
        // When none exists, do not fabricate.
        $existingInvoice = $receipt?->invoice ?? $order->invoices()->latest()->first();
        if ($existingInvoice) {
            $taxRate = $existingInvoice->tax_rate !== null ? (float) $existingInvoice->tax_rate : null;
            $response['invoice'] = [
                'invoice_number' => $existingInvoice->invoice_number,
                'invoice_date' => $existingInvoice->invoice_date?->format('Y-m-d'),
                'amount_before_tax' => (float) $existingInvoice->amount_before_tax,
                'tax_rate' => $taxRate,
                'tax_amount' => (float) $existingInvoice->tax_amount,
                'total_amount' => (float) $existingInvoice->total_amount,
            ];
            $response['financial_summary'] = [
                'amount_before_tax' => (float) $existingInvoice->amount_before_tax,
                'vat_rate' => $taxRate,
                'vat_amount' => (float) $existingInvoice->tax_amount,
                'total_amount' => (float) $existingInvoice->total_amount,
            ];
        }

        // Add unit_price and order_number from request if supplier_name and order_number are in request
        if (
            ! empty($requestData['supplier_name']) &&
            ! empty($requestData['order_number'])
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
                'damage' => 'Item quality variance detected.',
                'both' => "Item delivered is {$varianceAmount}{$varianceUnit} lower than requested & Confirmed Qty with quality issues.",
                default => 'Variance detected for this item.',
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

        if (! $receipt) {
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
                    'quality' => $item->quality_received?->value ?? $item->quality_ordered?->value,
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
            $order = PurchaseOrder::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->loadMissing(['items', 'branch']);

            $reqIdemKey = isset($deliveryDetails['idempotency_key']) && $deliveryDetails['idempotency_key'] !== ''
                ? (string) $deliveryDetails['idempotency_key']
                : null;
            if ($reqIdemKey) {
                $existingReceiptId = InventoryStockMovement::where('idempotency_key', 'like', 'transfer-req:'.$reqIdemKey.':%')
                    ->value('source_id');
                if ($existingReceiptId && ($existing = GoodsReceipt::with(['items', 'purchaseOrder'])->find($existingReceiptId))) {
                    return $existing;
                }
            }
            $companyId = (string) ($order->branch?->asab_company_id ?? \Modules\Branch\Models\Branch::whereKey($order->branch_id)->value('asab_company_id') ?? '');
            $now = now();

            $receivedByOrderItem = [];
            $receivedByItemId = [];
            foreach ((array) ($deliveryDetails['items'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (isset($row['purchase_order_item_id'])) {
                    $receivedByOrderItem[(string) $row['purchase_order_item_id']] = max(0.0, round((float) ($row['quantity_received'] ?? 0), 3));
                }
                if (isset($row['item_id'])) {
                    $receivedByItemId[(string) $row['item_id']] = max(0.0, round((float) ($row['quantity_received'] ?? 0), 3));
                }
            }

            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'received_by' => $receivedBy,
                'status' => 'in_progress',
                'driver_name' => $deliveryDetails['driver_name'] ?? null,
                'driver_contact' => $deliveryDetails['driver_contact'] ?? null,
                'vehicle_number' => $deliveryDetails['vehicle_number'] ?? null,
                'arrival_time' => $deliveryDetails['arrival_time'] ?? $now,
                'delivery_address' => $deliveryDetails['delivery_address'] ?? null,
                'total_items_expected' => $order->items->count(),
                'expected_amount' => $order->total_amount,
                'inspection_started_at' => $now,
            ]);

            $hasOpenTransit = false;

            foreach ($order->items as $orderItem) {
                $expectedQuantity = round((float) ($orderItem->quantity_confirmed ?? $orderItem->quantity_ordered), 3);
                $actualReceived = $receivedByOrderItem[$orderItem->id]
                    ?? ($orderItem->item_id ? ($receivedByItemId[(string) $orderItem->item_id] ?? $expectedQuantity) : $expectedQuantity);
                $actualReceived = min($expectedQuantity, max(0.0, round((float) $actualReceived, 3)));

                $expectedTotal = $expectedQuantity * (float) $orderItem->unit_price;
                $receivedTotal = $actualReceived * (float) $orderItem->unit_price;

                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $orderItem->id,
                    'item_id' => $orderItem->item_id,
                    'item_name' => $orderItem->item_name,
                    'item_logo' => $orderItem->item_logo,
                    'unit_of_measurement' => $orderItem->unit_of_measurement,
                    'quantity_ordered' => $expectedQuantity,
                    'quantity_received' => $actualReceived,
                    'quality_ordered' => $orderItem->quality_ordered,
                    'unit_price' => $orderItem->unit_price,
                    'expected_total' => $expectedTotal,
                    'received_total' => $receivedTotal,
                ]);

                $variance = InternalTransferVariance::where('purchase_order_item_id', $orderItem->id)
                    ->lockForUpdate()
                    ->first();

                $senderUnitCostHalalas = $variance
                    ? (int) $variance->sender_unit_cost_halalas
                    : (int) round(((float) $orderItem->unit_price) * 100);

                if ($actualReceived > 0.0) {
                    $itemId = (string) ($orderItem->item_id ?? '');
                    if ($itemId !== '') {
                        $this->adjustBranchStock($order->branch_id, $itemId, $actualReceived, $now);
                    }

                    $idemKey = $reqIdemKey
                        ? 'transfer-req:'.$reqIdemKey.':'.$orderItem->id
                        : 'transfer-receive:'.$receipt->id.':'.$orderItem->id;
                    if (! InventoryStockMovement::where('idempotency_key', $idemKey)->exists()) {
                        InventoryStockMovement::create([
                            'idempotency_key' => $idemKey,
                            'company_id' => $companyId,
                            'branch_id' => $order->branch_id,
                            'item_id' => $itemId !== '' ? $itemId : $orderItem->id,
                            'movement_type' => 'transfer_in',
                            'qty_delta' => $actualReceived,
                            'unit_cost_halalas' => $senderUnitCostHalalas,
                            'total_cost_halalas' => (int) round($actualReceived * $senderUnitCostHalalas),
                            'source_type' => 'goods_receipt',
                            'source_id' => $receipt->id,
                            'revision' => 1,
                            'cost_source' => 'SENDER_TRANSFER_COST',
                            'posted_at' => $now,
                            'created_by_id' => $receivedBy,
                            'notes' => 'استلام تحويل داخلي وارد — '.$order->order_number,
                        ]);
                    }
                }

                if ($variance) {
                    $newReceived = round((float) $variance->received_qty + $actualReceived, 3);
                    $inTransit = max(0.0, round((float) $variance->dispatched_qty - $newReceived, 3));
                    $updates = [
                        'goods_receipt_id' => $receipt->id,
                        'received_qty' => $newReceived,
                        'in_transit_qty' => $inTransit,
                        'variance_qty' => $inTransit,
                    ];
                    if ($inTransit <= 0.0) {
                        $updates['status'] = 'CLOSED';
                        $updates['resolution'] = 'full_receipt';
                        $updates['closed_by_id'] = $receivedBy;
                        $updates['closed_at'] = $now;
                    } else {
                        $updates['status'] = 'IN_TRANSIT';
                        $hasOpenTransit = true;
                    }
                    $variance->update($updates);
                } elseif ($actualReceived < $expectedQuantity) {
                    $inTransit = round($expectedQuantity - $actualReceived, 3);
                    InternalTransferVariance::create([
                        'purchase_order_id' => $order->id,
                        'purchase_order_item_id' => $orderItem->id,
                        'goods_receipt_id' => $receipt->id,
                        'from_branch_id' => $order->from_branch_id ?? $order->branch_id,
                        'to_branch_id' => $order->branch_id,
                        'item_id' => (string) ($orderItem->item_id ?? $orderItem->id),
                        'dispatched_qty' => $expectedQuantity,
                        'received_qty' => $actualReceived,
                        'in_transit_qty' => $inTransit,
                        'variance_qty' => $inTransit,
                        'sender_unit_cost_halalas' => $senderUnitCostHalalas,
                        'status' => 'IN_TRANSIT',
                    ]);
                    $hasOpenTransit = true;
                }
            }

            $receipt->completeInspection();

            if ($hasOpenTransit) {
                $order->update(['status' => OrderStatus::VARIANCE->value]);
            } else {
                $order->update([
                    'status' => OrderStatus::CLOSED->value,
                    'closed_at' => $now,
                ]);
                $order->items()->update(['status' => OrderItemStatus::CLOSED->value]);
                $this->timelineService->logOrderClosed($order);
            }

            $this->timelineService->logInspectionCompleted($receipt);

            return $receipt->fresh(['items', 'purchaseOrder']);
        });
    }

    /**
     * Close open internal-transfer variance (IN_TRANSIT) — receiving branch manager ONLY.
     * Supports: late_receipt | returned_to_sender | standing_variance.
     */
    public function closeTransferVariance(
        PurchaseOrder $order,
        string $actorId,
        ?string $actorBranchId,
        string $resolution,
        ?string $reason = null,
        ?string $evidencePath = null,
        ?string $idempotencyKey = null
    ): array {
        if ($actorBranchId !== $order->branch_id) {
            throw new AsabException(
                'FORBIDDEN_TRANSFER_CLOSURE',
                'Only the receiving branch manager can close internal transfer variance.',
                'إغلاق فروق التحويل الداخلي مسموح لمدير الفرع المستلم فقط',
                403
            );
        }

        if (! in_array($resolution, ['late_receipt', 'returned_to_sender', 'standing_variance'], true)) {
            throw new AsabException(
                'INVALID_VARIANCE_RESOLUTION',
                'Invalid variance resolution type.',
                'نوع تسوية الفرق غير صالح',
                422
            );
        }

        return DB::transaction(function () use ($order, $actorId, $resolution, $reason, $evidencePath, $idempotencyKey) {
            $order = PurchaseOrder::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->loadMissing('branch');
            $companyId = (string) ($order->branch?->asab_company_id ?? \Modules\Branch\Models\Branch::whereKey($order->branch_id)->value('asab_company_id') ?? '');
            $now = now();

            if ($idempotencyKey) {
                $replayed = InternalTransferVariance::where('purchase_order_id', $order->id)
                    ->where(function ($q) use ($idempotencyKey) {
                        $q->where('idempotency_key', $idempotencyKey)
                            ->orWhere('idempotency_key', 'like', $idempotencyKey.':%');
                    })
                    ->where('status', 'CLOSED')
                    ->get();
                if ($replayed->isNotEmpty()) {
                    return [
                        'order_id' => $order->id,
                        'order_status' => OrderStatus::CLOSED->value,
                        'resolution' => $replayed->first()->resolution,
                        'closed_variance_ids' => $replayed->pluck('id')->all(),
                        'idempotent_replay' => true,
                    ];
                }
            }

            $openVariances = InternalTransferVariance::where('purchase_order_id', $order->id)
                ->where('status', 'IN_TRANSIT')
                ->lockForUpdate()
                ->get();

            if ($openVariances->isEmpty()) {
                throw new AsabException(
                    'NO_OPEN_TRANSFER_VARIANCE',
                    'No open IN_TRANSIT variance exists for this transfer order.',
                    'لا توجد فروق تحويل قيد النقل مفتوحة لهذا الطلب',
                    409
                );
            }

            $closedIds = [];
            foreach ($openVariances as $variance) {
                $qty = round((float) $variance->in_transit_qty, 3);
                $unitCost = (int) $variance->sender_unit_cost_halalas;
                $totalCost = (int) round($qty * $unitCost);
                $idemKey = $idempotencyKey
                    ? 'transfer-close:'.$idempotencyKey.':'.$variance->id
                    : 'transfer-close:'.$variance->id.':'.$resolution;

                if ($qty > 0.0 && ! InventoryStockMovement::where('idempotency_key', $idemKey)->exists()) {
                    if ($resolution === 'late_receipt') {
                        $this->adjustBranchStock($variance->to_branch_id, $variance->item_id, $qty, $now);
                        $movementBranch = $variance->to_branch_id;
                        $movementType = 'transfer_in';
                        $qtyDelta = $qty;
                    } elseif ($resolution === 'returned_to_sender') {
                        $this->adjustBranchStock($variance->from_branch_id, $variance->item_id, $qty, $now);
                        $movementBranch = $variance->from_branch_id;
                        $movementType = 'transfer_return_in';
                        $qtyDelta = $qty;
                    } else {
                        $movementBranch = $variance->to_branch_id;
                        $movementType = 'transfer_variance_loss';
                        $qtyDelta = 0.0;
                    }

                    InventoryStockMovement::create([
                        'idempotency_key' => $idemKey,
                        'company_id' => $companyId,
                        'branch_id' => $movementBranch,
                        'item_id' => $variance->item_id,
                        'movement_type' => $movementType,
                        'qty_delta' => $qtyDelta,
                        'unit_cost_halalas' => $unitCost,
                        'total_cost_halalas' => $totalCost,
                        'source_type' => 'internal_transfer_variance',
                        'source_id' => $variance->id,
                        'revision' => 1,
                        'cost_source' => 'SENDER_TRANSFER_COST',
                        'posted_at' => $now,
                        'created_by_id' => $actorId,
                        'notes' => 'إغلاق فرق تحويل داخلي ('.$resolution.') — '.$order->order_number,
                    ]);
                }

                $variance->update([
                    'received_qty' => $resolution === 'late_receipt'
                        ? round((float) $variance->received_qty + $qty, 3)
                        : $variance->received_qty,
                    'in_transit_qty' => 0,
                    'variance_qty' => $resolution === 'standing_variance' ? $qty : 0,
                    'resolution' => $resolution,
                    'status' => 'CLOSED',
                    'reason' => $reason,
                    'evidence_path' => $evidencePath,
                    'idempotency_key' => $idempotencyKey ? $idempotencyKey.':'.$variance->id : null,
                    'closed_by_id' => $actorId,
                    'closed_at' => $now,
                ]);
                $closedIds[] = $variance->id;
            }

            $order->update([
                'status' => OrderStatus::CLOSED->value,
                'closed_at' => $now,
            ]);
            $this->timelineService->logOrderClosed($order);

            return [
                'order_id' => $order->id,
                'order_status' => OrderStatus::CLOSED->value,
                'resolution' => $resolution,
                'closed_variance_ids' => $closedIds,
            ];
        });
    }

    private function adjustBranchStock(string $branchId, string $itemId, float $deltaQty, \Carbon\CarbonInterface $now): void
    {
        $branchInv = BranchInventory::where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();
        if ($branchInv) {
            $branchInv->update([
                'available_quantity' => max(0.0, round((float) $branchInv->available_quantity + $deltaQty, 3)),
                'last_inventory_update' => $now,
            ]);
        }

        $branchItem = BranchItem::where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();
        if ($branchItem) {
            $branchItem->update([
                'quantity' => max(0.0, round((float) $branchItem->quantity + $deltaQty, 3)),
            ]);
        }
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

        if (! $order) {
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
        if (! empty($stages['variance_logged'])) {
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
            if ($order->delivery_photos && is_array($order->delivery_photos) && ! empty($order->delivery_photos)) {
                $uploadedAt = $order->actual_delivery_at?->format('Y-m-d H:i:s')
                    ?? $order->received_at?->format('Y-m-d H:i:s')
                    ?? null;

                $deliveredStage['delivery_photos'] = array_map(function ($photoPath) use ($uploadedAt) {
                    if (! is_string($photoPath)) {
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
                    'goods_inspections' => $latestReceipt->items
                        ->filter(fn ($item) => $item->variance_type !== null)
                        ->map(function ($item) {
                            return [
                                'item_name' => $item->item_name,
                                'item_logo' => $item->item_logo_url,
                                'item_unit' => $item->unit_of_measurement ?? 'kg',
                                'quantity_ordered' => (float) $item->quantity_ordered,
                                'quantity_received' => (float) $item->quantity_received,
                                'quality' => $item->quality_received?->value ?? $item->quality_ordered?->value,
                                'variance_type' => $item->variance_type->value,
                                'amount_variance' => (float) $item->variance_amount,
                                'temperature' => $item->temperature,
                                'expiration_date' => $item->expiry_date?->format('Y-m-d'),
                                'item_image' => FileResource::makeOrNull($item->photo)?->toArray(request()),
                                'additional_note' => $item->notes,
                            ];
                        })->values()->toArray(),
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
                        'quality' => $variance->quality_received?->value ?? $variance->quality_ordered?->value,
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
            if (! isset($quantity) || $quantity <= 0) {
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

            if (! $supplierExists) {
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
