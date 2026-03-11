<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Models\OrderTrackingStage;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Transformers\FileResource;

class OrderTrackingService
{
    /**
     * Save preparing stage data
     */
    public function savePreparingStage(PurchaseOrder $order, array $itemsData = []): OrderTrackingStage
    {
        // Ensure order is fresh with relationships
        if (!$order->relationLoaded('items')) {
            $order->load(['items.documents']);
        }

        // Check if preparing stage already exists and is not completed
        $existingStage = OrderTrackingStage::byOrder($order->id)
            ->byType('preparing')
            ->active()
            ->first();

        if ($existingStage) {
            // Update existing stage
            $stageData = $this->preparePreparingStageData($order, $itemsData);
            $existingStage->update(['stage_data' => $stageData]);
            return $existingStage->fresh();
        }

        // Create new stage
        return OrderTrackingStage::create([
            'purchase_order_id' => $order->id,
            'stage_type' => 'preparing',
            'stage_data' => $this->preparePreparingStageData($order, $itemsData),
            'started_at' => now(),
            'created_by' => Auth::id(),
            'created_by_type' => Auth::user() ? get_class(Auth::user()) : null,
        ]);
    }

    /**
     * Save out for delivery stage data
     */
    public function saveOutForDeliveryStage(PurchaseOrder $order, array $deliveryData = []): OrderTrackingStage
    {
        // Mark previous preparing stage as completed if exists
        $preparingStage = OrderTrackingStage::byOrder($order->id)
            ->byType('preparing')
            ->active()
            ->first();

        if ($preparingStage) {
            $preparingStage->markAsCompleted();
        }

        // Check if out_for_delivery stage already exists
        $existingStage = OrderTrackingStage::byOrder($order->id)
            ->byType('out_for_delivery')
            ->active()
            ->first();

        if ($existingStage) {
            $stageData = $this->prepareOutForDeliveryStageData($order, $deliveryData);
            $existingStage->update(['stage_data' => $stageData]);
            return $existingStage->fresh();
        }

        return OrderTrackingStage::create([
            'purchase_order_id' => $order->id,
            'stage_type' => 'out_for_delivery',
            'stage_data' => $this->prepareOutForDeliveryStageData($order, $deliveryData),
            'started_at' => now(),
            'created_by' => Auth::id(),
            'created_by_type' => Auth::user() ? get_class(Auth::user()) : null,
        ]);
    }

    /**
     * Save delivered stage data
     */
    public function saveDeliveredStage(PurchaseOrder $order, ?array $invoiceData = null): OrderTrackingStage
    {
        // Ensure order is fresh with relationships
        if (!$order->relationLoaded('latestGoodsReceipt')) {
            $order->load('latestGoodsReceipt.invoice');
        }

        // Mark previous out_for_delivery stage as completed if exists
        $outForDeliveryStage = OrderTrackingStage::byOrder($order->id)
            ->byType('out_for_delivery')
            ->active()
            ->first();

        if ($outForDeliveryStage) {
            $outForDeliveryStage->markAsCompleted();
        }

        // Check if delivered stage already exists
        $existingStage = OrderTrackingStage::byOrder($order->id)
            ->byType('delivered')
            ->active()
            ->first();

        if ($existingStage) {
            $stageData = $this->prepareDeliveredStageData($order, $invoiceData);
            $existingStage->update(['stage_data' => $stageData]);
            return $existingStage->fresh();
        }

        return OrderTrackingStage::create([
            'purchase_order_id' => $order->id,
            'stage_type' => 'delivered',
            'stage_data' => $this->prepareDeliveredStageData($order, $invoiceData),
            'started_at' => now(),
            'created_by' => Auth::id(),
            'created_by_type' => Auth::user() ? get_class(Auth::user()) : null,
        ]);
    }

    /**
     * Save order confirmation stage data
     */
    public function saveOrderConfirmationStage(PurchaseOrder $order): ?OrderTrackingStage
    {
        // Refresh order and load latest receipt to ensure we have the latest data
        $order->refresh();
        $order->load('latestGoodsReceipt');

        $latestReceipt = $order->latestGoodsReceipt;

        // If latestGoodsReceipt doesn't have items, try to get the most recent receipt with items
        if ($latestReceipt && $latestReceipt->items->isEmpty()) {
            $receiptWithItems = \Modules\Purchase\Models\GoodsReceipt::where('purchase_order_id', $order->id)
                ->whereHas('items')
                ->orderBy('created_at', 'desc')
                ->with('items')
                ->first();

            if ($receiptWithItems) {
                \Log::info('OrderTracking: saveOrderConfirmationStage - Using receipt with items', [
                    'order_id' => $order->id,
                    'old_receipt_id' => $latestReceipt->id,
                    'new_receipt_id' => $receiptWithItems->id,
                    'items_count' => $receiptWithItems->items->count(),
                ]);
                $latestReceipt = $receiptWithItems;
            }
        }

        // Allow creation if receipt is completed OR order is closed (fallback for edge cases)
        if (!$latestReceipt || (!$latestReceipt->is_completed && $order->status !== \Modules\Purchase\Enums\OrderStatus::CLOSED)) {
            return null;
        }

        // Mark previous delivered stage as completed if exists
        $deliveredStage = OrderTrackingStage::byOrder($order->id)
            ->byType('delivered')
            ->active()
            ->first();

        if ($deliveredStage) {
            $deliveredStage->markAsCompleted();
        }

        // Check if order_confirmation stage already exists
        $existingStage = OrderTrackingStage::byOrder($order->id)
            ->byType('order_confirmation')
            ->first();

        if ($existingStage) {
            $stageData = $this->prepareOrderConfirmationStageData($order, $latestReceipt);
            $existingStage->update(['stage_data' => $stageData]);
            return $existingStage->fresh();
        }

        return OrderTrackingStage::create([
            'purchase_order_id' => $order->id,
            'stage_type' => 'order_confirmation',
            'stage_data' => $this->prepareOrderConfirmationStageData($order, $latestReceipt),
            'started_at' => $latestReceipt->inspection_completed_at ?? now(),
            'completed_at' => $latestReceipt->inspection_completed_at ?? now(),
            'created_by' => Auth::id(),
            'created_by_type' => Auth::user() ? get_class(Auth::user()) : null,
        ]);
    }

    /**
     * Save variance logged stage data
     */
    public function saveVarianceLoggedStage(PurchaseOrder $order): ?OrderTrackingStage
    {
        $latestReceipt = $order->latestGoodsReceipt;

        if (!$latestReceipt || !$latestReceipt->hasVariances) {
            return null;
        }

        // Check if variance_logged stage already exists
        $existingStage = OrderTrackingStage::byOrder($order->id)
            ->byType('variance_logged')
            ->first();

        $stageData = $this->prepareVarianceLoggedStageData($order, $latestReceipt);

        if ($existingStage) {
            $existingStage->update(['stage_data' => $stageData]);
            return $existingStage->fresh();
        }

        return OrderTrackingStage::create([
            'purchase_order_id' => $order->id,
            'stage_type' => 'variance_logged',
            'stage_data' => $stageData,
            'started_at' => now(),
            'created_by' => Auth::id(),
            'created_by_type' => Auth::user() ? get_class(Auth::user()) : null,
        ]);
    }

    /**
     * Get all tracking stages for an order
     */
    public function getOrderTrackingStages(string $orderId): array
    {
        $order = PurchaseOrder::with([
            'items.documents',
            'latestGoodsReceipt.invoice',
            'latestGoodsReceipt.documents',
            'latestGoodsReceipt.items',
            'latestGoodsReceipt.variances',
        ])->find($orderId);

        if (!$order) {
            return [];
        }

        // Ensure latestGoodsReceipt is the most recent one and has items loaded
        if ($order->latestGoodsReceipt) {
            // Force reload to get fresh data
            $order->latestGoodsReceipt->refresh();
            $order->latestGoodsReceipt->load('items');

            // Log for debugging
            \Log::info('OrderTracking: getOrderTrackingStages', [
                'order_id' => $orderId,
                'receipt_id' => $order->latestGoodsReceipt->id,
                'receipt_status' => $order->latestGoodsReceipt->status,
                'items_count' => $order->latestGoodsReceipt->items->count(),
                'all_receipts_count' => \Modules\Purchase\Models\GoodsReceipt::where('purchase_order_id', $orderId)->count(),
            ]);
        }

        $stages = OrderTrackingStage::byOrder($orderId)
            ->ordered()
            ->get()
            ->keyBy('stage_type')
            ->map(function ($stage) use ($order) {
                $stageData = $stage->stage_data;

                // Enhance stage data with fresh relationships if needed
                switch ($stage->stage_type) {
                    case 'preparing':
                        // Ensure items have quality certificates and item_unit
                        if (isset($stageData['items']) && is_array($stageData['items'])) {
                            foreach ($stageData['items'] as $key => $item) {
                                if (empty($item['item_unit'])) {
                                    $orderItem = $order->items->firstWhere('item_name', $item['item_name'] ?? '');
                                    $stageData['items'][$key]['item_unit'] = $orderItem?->unit_of_measurement ?? 'kg';
                                }
                            }
                            // Load SupplierQualityDocuments for this order
                            $qualityDocuments = \Modules\Supplier\Models\SupplierQualityDocument::where('order_id', $order->id)
                                ->where('document_type', 'certificate')
                                ->where('is_active', true)
                                ->get()
                                ->keyBy(function ($doc) {
                                    if (str_contains($doc->title, 'Item Document - ')) {
                                        return str_replace('Item Document - ', '', $doc->title);
                                    }
                                    return null;
                                });

                            foreach ($stageData['items'] as $key => $item) {
                                $needsRefresh = !isset($item['quality_certificate'])
                                    || (is_array($item['quality_certificate'] ?? null) && ($item['quality_certificate']['file_size'] ?? null) === null);
                                if (!$needsRefresh) {
                                    continue;
                                }
                                $orderItem = $order->items->firstWhere('item_name', $item['item_name']);

                                // First try OrderDocument
                                if ($orderItem) {
                                    if ($orderItem->relationLoaded('documents')) {
                                        $qualityCert = $orderItem->documents
                                            ->where('type', DocumentType::QUALITY_CERTIFICATE)
                                            ->first();
                                    } else {
                                        $qualityCert = $orderItem->documents()
                                            ->where('type', DocumentType::QUALITY_CERTIFICATE)
                                            ->first();
                                    }

                                    if ($qualityCert) {
                                        $stageData['items'][$key]['quality_certificate'] = FileResource::makeOrNull($qualityCert)?->toArray(request());
                                        continue;
                                    }
                                }

                                // Try SupplierQualityDocument
                                $supplierDoc = $qualityDocuments->get($item['item_name']);
                                if ($supplierDoc && $supplierDoc->file_path) {
                                    $fileType = $supplierDoc->file_type ?? pathinfo($supplierDoc->file_path, PATHINFO_EXTENSION);
                                    $fileSize = $supplierDoc->file_size ?? null;
                                    if ($fileSize === null) {
                                        try {
                                            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($supplierDoc->file_path)) {
                                                $fileSize = \Illuminate\Support\Facades\Storage::disk('public')->size($supplierDoc->file_path);
                                            }
                                        } catch (\Throwable) {
                                            // keep null
                                        }
                                    }
                                    $stageData['items'][$key]['quality_certificate'] = FileResource::makeOrNull([
                                        'id' => (string) $supplierDoc->id,
                                        'file_path' => $supplierDoc->file_path,
                                        'file_name' => $supplierDoc->file_name ?? basename($supplierDoc->file_path),
                                        'file_type' => $fileType ? strtolower($fileType) : null,
                                        'file_size' => $fileSize,
                                        'created_at' => $supplierDoc->created_at,
                                    ])?->toArray(request());
                                }
                            }
                        }
                        break;

                    case 'out_for_delivery':
                        $order->loadMissing('latestGoodsReceipt');
                        $latestReceipt = $order->latestGoodsReceipt;
                        $stageData['driver_details'] = $stageData['driver_details'] ?? [];
                        $path = $order->driver_photo ?? $latestReceipt?->driver_image ?? null;
                        $storedPhoto = $stageData['driver_details']['photo'] ?? null;
                        if ($path !== null) {
                            $stageData['driver_details']['photo'] = FileResource::makeOrNull($path)?->toArray(request());
                        } elseif (is_string($storedPhoto)) {
                            $stageData['driver_details']['photo'] = FileResource::makeOrNull($storedPhoto)?->toArray(request());
                        }
                        break;

                    case 'delivered':
                        // Ensure invoice or delivery_note file is included
                        if (!$order->relationLoaded('latestGoodsReceipt')) {
                            $order->load('latestGoodsReceipt.invoice', 'latestGoodsReceipt.documents');
                        } else {
                            $order->latestGoodsReceipt?->loadMissing(['invoice', 'documents']);
                        }

                        $latestReceipt = $order->latestGoodsReceipt;
                        $invoice = $latestReceipt?->invoice;

                        if ($invoice) {
                            $stageData['invoice_file'] = FileResource::makeOrNull($invoice)?->toArray(request());
                        } else {
                            $supplierInvoice = \Modules\Supplier\Models\SupplierInvoice::where('order_id', $order->id)
                                ->latest()
                                ->first();
                            if ($supplierInvoice) {
                                $stageData['invoice_file'] = FileResource::makeOrNull([
                                    'id' => (string) $supplierInvoice->id,
                                    'file_path' => $supplierInvoice->file_path,
                                    'created_at' => $supplierInvoice->created_at,
                                ])?->toArray(request());
                            } elseif ($latestReceipt?->document_type?->value === 'delivery_note') {
                                $deliveryNoteDoc = $latestReceipt->documents
                                    ->where('type', DocumentType::DELIVERY_NOTE)
                                    ->first();
                                if ($deliveryNoteDoc) {
                                    $stageData['delivery_note_file'] = FileResource::makeOrNull($deliveryNoteDoc)?->toArray(request());
                                }
                            }
                        }

                        // Add delivery_photos from order if available (enrichment)
                        if ($order->delivery_photos && is_array($order->delivery_photos) && !empty($order->delivery_photos)) {
                            $uploadedAt = $order->actual_delivery_at?->format('Y-m-d H:i:s')
                                ?? $order->received_at?->format('Y-m-d H:i:s')
                                ?? null;

                            $stageData['delivery_photos'] = array_map(function ($photoPath) use ($uploadedAt) {
                                if (!is_string($photoPath)) {
                                    return FileResource::makeOrNull($photoPath)?->toArray(request());
                                }

                                // Get file info from storage
                                $fileType = pathinfo($photoPath, PATHINFO_EXTENSION);
                                $fileSize = null;
                                try {
                                    if (Storage::disk('public')->exists($photoPath)) {
                                        $fileSize = Storage::disk('public')->size($photoPath);
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
                        break;

                    case 'order_confirmation':
                        $order->loadMissing(['latestGoodsReceipt', 'supplier']);
                        $latestReceipt = $order->latestGoodsReceipt;

                        // If latestGoodsReceipt doesn't have items, try to get the most recent receipt with items
                        if ($latestReceipt && $latestReceipt->items->isEmpty()) {
                            $receiptWithItems = \Modules\Purchase\Models\GoodsReceipt::where('purchase_order_id', $order->id)
                                ->whereHas('items')
                                ->orderBy('created_at', 'desc')
                                ->with('items')
                                ->first();

                            if ($receiptWithItems) {
                                \Log::info('OrderTracking: Using receipt with items instead of latestGoodsReceipt', [
                                    'order_id' => $order->id,
                                    'old_receipt_id' => $latestReceipt->id,
                                    'new_receipt_id' => $receiptWithItems->id,
                                    'items_count' => $receiptWithItems->items->count(),
                                ]);
                                $latestReceipt = $receiptWithItems;
                            }
                        }

                        if ($latestReceipt) {
                            $stageData = $this->enrichOrderConfirmationStageData($stageData, $order, $latestReceipt);
                        }
                        break;

                    case 'variance_logged':
                        // Ensure each variance has item_unit (when loaded from DB it may be missing)
                        if (isset($stageData['variances']) && is_array($stageData['variances'])) {
                            $order->loadMissing(['latestGoodsReceipt.variances.goodsReceiptItem']);
                            $variancesByItemName = $order->latestGoodsReceipt?->variances?->keyBy('item_name') ?? collect();
                            foreach ($stageData['variances'] as $vKey => $variance) {
                                $itemName = $variance['item_name'] ?? null;
                                if (empty($stageData['variances'][$vKey]['item_unit']) && $itemName) {
                                    $varianceModel = $variancesByItemName->get($itemName);
                                    $stageData['variances'][$vKey]['item_unit'] = $varianceModel?->goodsReceiptItem?->unit_of_measurement ?? 'kg';
                                }
                            }
                        }
                        break;
                }

                return $stageData;
            })
            ->toArray();

        // If order_confirmation stage doesn't exist but receipt is completed or order is closed, add it
        if (!isset($stages['order_confirmation'])) {
            $latestReceipt = $order->latestGoodsReceipt;
            // Create order_confirmation if receipt is completed OR order is closed (fallback)
            if ($latestReceipt && ($latestReceipt->is_completed || $order->status === \Modules\Purchase\Enums\OrderStatus::CLOSED)) {
                $orderConfirmationStage = $this->saveOrderConfirmationStage($order);
                if ($orderConfirmationStage) {
                    $stages['order_confirmation'] = $this->enrichOrderConfirmationStageData(
                        $orderConfirmationStage->stage_data,
                        $order,
                        $latestReceipt
                    );
                } elseif ($order->status === \Modules\Purchase\Enums\OrderStatus::CLOSED && $latestReceipt) {
                    // If saveOrderConfirmationStage returned null (receipt not completed),
                    // but order is closed, create the stage directly
                    $stageData = $this->prepareOrderConfirmationStageData($order, $latestReceipt);
                    $orderConfirmationStage = OrderTrackingStage::create([
                        'purchase_order_id' => $order->id,
                        'stage_type' => 'order_confirmation',
                        'stage_data' => $stageData,
                        'started_at' => $latestReceipt->inspection_completed_at ?? now(),
                        'completed_at' => $latestReceipt->inspection_completed_at ?? now(),
                        'created_by' => Auth::id(),
                        'created_by_type' => Auth::user() ? get_class(Auth::user()) : null,
                    ]);
                    $stages['order_confirmation'] = $this->enrichOrderConfirmationStageData(
                        $orderConfirmationStage->stage_data,
                        $order,
                        $latestReceipt
                    );
                }
            }
        }

        // If variance_logged stage doesn't exist but receipt has variances, add it
        if (!isset($stages['variance_logged'])) {
            $latestReceipt = $order->latestGoodsReceipt;
            if ($latestReceipt && $latestReceipt->hasVariances) {
                // Try to save the stage (it will be created if it doesn't exist)
                $varianceStage = $this->saveVarianceLoggedStage($order);
                if ($varianceStage) {
                    $stages['variance_logged'] = $varianceStage->stage_data;
                }
            }
        }

        return $stages;
    }

    /**
     * Enrich order_confirmation stage_data with fresh driver/arrival fallbacks,
     * item_image as FileResource, and financial_summary (from invoice or calculated from received_amount).
     */
    private function enrichOrderConfirmationStageData(array $stageData, PurchaseOrder $order, $latestReceipt): array
    {
        // Force reload receipt with items to ensure fresh data
        $latestReceipt = $latestReceipt->fresh(['invoice', 'items']);

        $order->loadMissing('supplier');
        $inv = $latestReceipt->invoice;
        $r = $stageData['receipt_details'] ?? [];
        $ins = $r['inspection_summary'] ?? [];

        $stageData['receipt_details'] = $stageData['receipt_details'] ?? [];

        // Ensure receipt items are loaded - reload if needed
        if (!$latestReceipt->relationLoaded('items') || $latestReceipt->items->isEmpty()) {
            // Try to reload items directly from database
            $itemsCount = \Modules\Purchase\Models\GoodsReceiptItem::where('goods_receipt_id', $latestReceipt->id)->count();
            \Log::info('OrderTracking: Reloading receipt items', [
                'receipt_id' => $latestReceipt->id,
                'items_in_db' => $itemsCount,
                'items_loaded' => $latestReceipt->relationLoaded('items'),
                'items_count' => $latestReceipt->items->count(),
            ]);

            $latestReceipt->load('items');

            // If still empty, try direct query
            if ($latestReceipt->items->isEmpty() && $itemsCount > 0) {
                $latestReceipt->setRelation('items', \Modules\Purchase\Models\GoodsReceiptItem::where('goods_receipt_id', $latestReceipt->id)->get());
            }
        }

        // Calculate values from items if receipt fields are 0 (fallback)
        $items = $latestReceipt->items;
        $numberOfItems = $latestReceipt->total_items_received > 0
            ? $latestReceipt->total_items_received
            : $items->whereNotNull('quantity_received')->count();

        $quantityVariance = $latestReceipt->quantity_variances > 0
            ? $latestReceipt->quantity_variances
            : $items->filter(fn($item) => $item->quantity_variance != 0)->count();

        $totalAmount = $latestReceipt->received_amount > 0
            ? (float) $latestReceipt->received_amount
            : (float) $items->sum('received_total');

        $stageData['receipt_details']['inspection_summary'] = array_merge($ins, [
            'number_of_items' => $ins['number_of_items'] ?? $numberOfItems,
            'quantity_variance' => $ins['quantity_variance'] ?? $quantityVariance,
            'total_amount' => $ins['total_amount'] ?? $totalAmount,
            'driver_name' => $ins['driver_name'] ?? $latestReceipt->driver_name ?? $order->driver_name,
            'contact_number' => $ins['contact_number'] ?? $latestReceipt->driver_contact ?? $order->driver_contact ?? null,
            'vehicle_number' => $ins['vehicle_number'] ?? $latestReceipt->vehicle_number ?? $order->vehicle_number,
            'arrival_time' => $ins['arrival_time'] ?? $latestReceipt->arrival_time?->format('Y-m-d H:i:s') ?? $order->expected_delivery_at?->format('Y-m-d H:i:s'),
        ]);

        $goodsInspections = $stageData['receipt_details']['goods_inspections'] ?? [];

        // Debug: Log receipt items count
        \Log::info('OrderTracking: enrichOrderConfirmationStageData', [
            'order_id' => $order->id,
            'receipt_id' => $latestReceipt->id,
            'items_count' => $latestReceipt->items->count(),
            'items_loaded' => $latestReceipt->relationLoaded('items'),
            'existing_goods_inspections_count' => count($goodsInspections),
        ]);

        // Always repopulate goods_inspections from receipt items to ensure fresh data
        // This fixes the issue where empty array is stored but items exist
        if ($latestReceipt->items->isNotEmpty()) {
            $goodsInspections = $latestReceipt->items
                ->filter(fn($item) => $item->variance_type !== null)
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
                })->values()->toArray();
            $stageData['receipt_details']['goods_inspections'] = $goodsInspections;
        } else {
            \Log::warning('OrderTracking: No items in receipt', [
                'order_id' => $order->id,
                'receipt_id' => $latestReceipt->id,
            ]);

            // If no items in receipt, keep existing goods_inspections (if any) but enrich item_image and item_unit
            if (!empty($goodsInspections)) {
                foreach ($goodsInspections as $giKey => $gi) {
                    $item = $latestReceipt->items->firstWhere('item_name', $gi['item_name'] ?? '');
                    $stageData['receipt_details']['goods_inspections'][$giKey]['item_image'] = FileResource::makeOrNull($item?->photo ?? null)?->toArray(request());
                    $stageData['receipt_details']['goods_inspections'][$giKey]['item_unit'] = $item?->unit_of_measurement ?? $gi['item_unit'] ?? 'kg';
                }
            }
        }

        // Ensure date_time is set (never null): inspection_completed_at then updated_at then created_at
        if (empty($stageData['receipt_details']['date_time'])) {
            $dateTime = $latestReceipt->inspection_completed_at
                ?? $latestReceipt->updated_at
                ?? $latestReceipt->created_at;
            $stageData['receipt_details']['date_time'] = $dateTime?->format('Y-m-d H:i:s')
                ?? now()->format('Y-m-d H:i:s');
        }

        // Ensure document_type is set (fallback if missing)
        if (empty($stageData['receipt_details']['document_type'])) {
            $stageData['receipt_details']['document_type'] = $this->determineDocumentType($latestReceipt, $inv);
        }

        // Calculate financial_summary: from invoice if exists, otherwise from received_amount
        if (empty($stageData['receipt_details']['financial_summary'])) {
            $stageData['receipt_details']['financial_summary'] = $this->calculateFinancialSummary($order, $latestReceipt, $inv);
        }

        return $stageData;
    }

    /**
     * Prepare preparing stage data
     */
    private function preparePreparingStageData(PurchaseOrder $order, array $itemsData = []): array
    {
        // Load relationships if not already loaded
        if (!$order->relationLoaded('items')) {
            $order->load(['items.documents']);
        } else {
            // If items are loaded but documents are not, load them
            $order->items->loadMissing('documents');
        }

        // Load SupplierQualityDocuments for this order
        $qualityDocuments = \Modules\Supplier\Models\SupplierQualityDocument::where('order_id', $order->id)
            ->where('document_type', 'certificate')
            ->where('is_active', true)
            ->get()
            ->keyBy(function ($doc) {
                // Extract item name from title (format: "Item Document - {item_name}")
                if (str_contains($doc->title, 'Item Document - ')) {
                    return str_replace('Item Document - ', '', $doc->title);
                }
                return null;
            });

        $items = $order->items->map(function ($item) use ($qualityDocuments) {
            $itemData = [
                'item_name' => $item->item_name,
                'item_logo' => $item->item_logo,
                'item_unit' => $item->unit_of_measurement ?? 'kg',
                'requested_quantity' => (float) $item->quantity_ordered,
                'status' => 'Preparing',
            ];

            // First try to get from OrderDocument (if exists)
            if ($item->relationLoaded('documents')) {
                $qualityCert = $item->documents
                    ->where('type', DocumentType::QUALITY_CERTIFICATE)
                    ->first();
            } else {
                $qualityCert = $item->documents()
                    ->where('type', DocumentType::QUALITY_CERTIFICATE)
                    ->first();
            }

            if ($qualityCert) {
                $itemData['quality_certificate'] = FileResource::makeOrNull($qualityCert)?->toArray(request());
            } else {
                // Try to get from SupplierQualityDocument
                $supplierDoc = $qualityDocuments->get($item->item_name);
                if ($supplierDoc && $supplierDoc->file_path) {
                    $fileType = $supplierDoc->file_type ?? pathinfo($supplierDoc->file_path, PATHINFO_EXTENSION);
                    $fileSize = $supplierDoc->file_size ?? null;
                    if ($fileSize === null) {
                        try {
                            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($supplierDoc->file_path)) {
                                $fileSize = \Illuminate\Support\Facades\Storage::disk('public')->size($supplierDoc->file_path);
                            }
                        } catch (\Throwable) {
                            // keep null
                        }
                    }
                    $itemData['quality_certificate'] = FileResource::makeOrNull([
                        'id' => (string) $supplierDoc->id,
                        'file_path' => $supplierDoc->file_path,
                        'file_name' => $supplierDoc->file_name ?? basename($supplierDoc->file_path),
                        'file_type' => $fileType ? strtolower($fileType) : null,
                        'file_size' => $fileSize,
                        'created_at' => $supplierDoc->created_at,
                    ])?->toArray(request());
                }
            }

            return $itemData;
        });

        return [
            'status' => 'preparing',
            'items' => $items->toArray(),
            'started_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Prepare out for delivery stage data
     */
    private function prepareOutForDeliveryStageData(PurchaseOrder $order, array $deliveryData = []): array
    {
        $latestReceipt = $order->latestGoodsReceipt;
        $driverPhotoPath = $order->driver_photo ?? $latestReceipt?->driver_image ?? null;

        return [
            'status' => 'out_for_delivery',
            'arrival_time' => $order->expected_delivery_at?->format('Y-m-d H:i:s'),
            'driver_details' => [
                'name' => $order->driver_name ?? $deliveryData['driver_name'] ?? null,
                'photo' => FileResource::makeOrNull($driverPhotoPath)?->toArray(request()),
                'vehicle_number' => $order->vehicle_number ?? $deliveryData['vehicle_number'] ?? null,
            ],
            'delivery_address' => $latestReceipt?->delivery_address ?? $deliveryData['delivery_address'] ?? null,
            'delivery_notes' => $latestReceipt?->delivery_notes ?? $deliveryData['notes'] ?? null,
            'started_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Prepare delivered stage data
     */
    private function prepareDeliveredStageData(PurchaseOrder $order, ?array $invoiceData = null): array
    {
        // Load latest receipt with invoice and documents
        if (!$order->relationLoaded('latestGoodsReceipt')) {
            $order->load('latestGoodsReceipt.invoice', 'latestGoodsReceipt.documents');
        } else {
            $order->latestGoodsReceipt?->loadMissing(['invoice', 'documents']);
        }

        $latestReceipt = $order->latestGoodsReceipt;
        $invoice = $latestReceipt?->invoice;

        // If no invoice in PurchaseInvoice, try SupplierInvoice
        if (!$invoice) {
            $supplierInvoice = \Modules\Supplier\Models\SupplierInvoice::where('order_id', $order->id)
                ->latest()
                ->first();

            if ($supplierInvoice) {
                $stageData = [
                    'status' => 'delivered',
                    'started_at' => now()->format('Y-m-d H:i:s'),
                    'invoice_file' => FileResource::makeOrNull([
                        'id' => (string) $supplierInvoice->id,
                        'file_path' => $supplierInvoice->file_path,
                        'created_at' => $supplierInvoice->created_at,
                    ])?->toArray(request()),
                ];
                return $stageData;
            }
        }

        $stageData = [
            'status' => 'delivered',
            'started_at' => now()->format('Y-m-d H:i:s'),
        ];

        if ($invoice) {
            $stageData['invoice_file'] = FileResource::makeOrNull($invoice)?->toArray(request());
        } elseif ($latestReceipt && $latestReceipt->document_type?->value === 'delivery_note') {
            // When document_type is delivery_note, use delivery note document as file
            $deliveryNoteDoc = $latestReceipt->documents
                ->where('type', DocumentType::DELIVERY_NOTE)
                ->first();
            if ($deliveryNoteDoc) {
                $stageData['delivery_note_file'] = FileResource::makeOrNull($deliveryNoteDoc)?->toArray(request());
            }
        }

        // Add delivery_photos from order if available
        if ($order->delivery_photos && is_array($order->delivery_photos) && !empty($order->delivery_photos)) {
            $uploadedAt = $order->actual_delivery_at?->format('Y-m-d H:i:s')
                ?? $order->received_at?->format('Y-m-d H:i:s')
                ?? null;

            $stageData['delivery_photos'] = array_map(function ($photoPath) use ($uploadedAt) {
                if (!is_string($photoPath)) {
                    return FileResource::makeOrNull($photoPath)?->toArray(request());
                }

                // Get file info from storage
                $fileType = pathinfo($photoPath, PATHINFO_EXTENSION);
                $fileSize = null;
                try {
                    if (Storage::disk('public')->exists($photoPath)) {
                        $fileSize = Storage::disk('public')->size($photoPath);
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

        return $stageData;
    }

    /**
     * Prepare order confirmation stage data
     */
    private function prepareOrderConfirmationStageData(PurchaseOrder $order, $latestReceipt): array
    {
        $latestReceipt->loadMissing(['invoice', 'items', 'documents']);
        $order->loadMissing('supplier');
        $invoice = $latestReceipt->invoice;

        // Calculate values from items if receipt fields are 0 (fallback)
        $items = $latestReceipt->items;
        $numberOfItems = $latestReceipt->total_items_received > 0
            ? $latestReceipt->total_items_received
            : $items->whereNotNull('quantity_received')->count();

        $quantityVariance = $latestReceipt->quantity_variances > 0
            ? $latestReceipt->quantity_variances
            : $items->filter(fn($item) => $item->quantity_variance != 0)->count();

        $totalAmount = $latestReceipt->received_amount > 0
            ? (float) $latestReceipt->received_amount
            : (float) $items->sum('received_total');

        $dateTime = $latestReceipt->inspection_completed_at
            ?? $latestReceipt->updated_at
            ?? $latestReceipt->created_at;

        return [
            'status' => 'confirmed',
            'receipt_details' => [
                'date_time' => $dateTime?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                'inspection_summary' => [
                    'number_of_items' => $numberOfItems,
                    'quantity_variance' => $quantityVariance,
                    'total_amount' => $totalAmount,
                    'driver_name' => $latestReceipt->driver_name ?? $order->driver_name,
                    'contact_number' => $latestReceipt->driver_contact ?? $order->driver_contact ?? null,
                    'vehicle_number' => $latestReceipt->vehicle_number ?? $order->vehicle_number,
                    'arrival_time' => $latestReceipt->arrival_time?->format('Y-m-d H:i:s')
                        ?? $order->expected_delivery_at?->format('Y-m-d H:i:s'),
                ],
                'goods_inspections' => $latestReceipt->items
                    ->filter(fn($item) => $item->variance_type !== null)
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
                'document_type' => $this->determineDocumentType($latestReceipt, $invoice),
                'financial_summary' => $this->calculateFinancialSummary($order, $latestReceipt, $invoice),
            ],
        ];
    }

    /**
     * Determine document_type from receipt, invoice, or documents
     */
    private function determineDocumentType($latestReceipt, $invoice): ?string
    {
        // First try receipt document_type
        if ($latestReceipt->document_type?->value) {
            return $latestReceipt->document_type->value;
        }

        // Fallback: determine from invoice or delivery_note document
        if ($invoice) {
            return 'invoice';
        }

        $latestReceipt->loadMissing('documents');
        $hasDeliveryNote = $latestReceipt->documents
            ->where('type', DocumentType::DELIVERY_NOTE)
            ->isNotEmpty();

        return $hasDeliveryNote ? 'delivery_note' : 'receipt_without_document';
    }

    /**
     * Calculate financial summary from invoice, received_amount, or order total_amount
     */
    private function calculateFinancialSummary(PurchaseOrder $order, $latestReceipt, $invoice): ?array
    {
        if ($invoice) {
            // Use invoice data
            return [
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
                'supplier_name' => $order->supplier?->name,
                'amount_before_tax' => (float) $invoice->amount_before_tax,
                'vat' => (float) $invoice->tax_amount,
                'total_amount' => (float) $invoice->total_amount,
            ];
        }

        $calculationService = app(\Modules\Purchase\Services\CalculationService::class);
        $amountToUse = null;

        // Try received_amount first
        if ($latestReceipt && $latestReceipt->received_amount > 0) {
            $amountToUse = (float) $latestReceipt->received_amount;
        }
        // Fallback to expected_amount if received_amount is 0
        elseif ($latestReceipt && $latestReceipt->expected_amount > 0) {
            $amountToUse = (float) $latestReceipt->expected_amount;
        }
        // Fallback to order total_amount
        elseif ($order->total_amount > 0) {
            $amountToUse = (float) $order->total_amount;
        }

        if ($amountToUse !== null && $amountToUse > 0) {
            $financialData = $calculationService->calculateTotalWithVAT($amountToUse);

            return [
                'invoice_number' => $order->order_number ?? $latestReceipt?->receipt_number ?? null,
                'invoice_date' => $latestReceipt?->inspection_completed_at?->format('Y-m-d') ?? $order->closed_at?->format('Y-m-d'),
                'supplier_name' => $order->supplier?->name,
                'amount_before_tax' => $financialData['amount_before_tax'],
                'vat' => $financialData['vat_amount'],
                'total_amount' => $financialData['total_amount'],
            ];
        }

        return null;
    }

    /**
     * Prepare variance logged stage data
     */
    private function prepareVarianceLoggedStageData(PurchaseOrder $order, $latestReceipt): array
    {
        return [
            'status' => 'variance_logged',
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
}
