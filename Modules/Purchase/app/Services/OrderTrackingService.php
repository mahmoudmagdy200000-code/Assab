<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $latestReceipt = $order->latestGoodsReceipt;
        
        if (!$latestReceipt || !$latestReceipt->is_completed) {
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
            'latestGoodsReceipt.items',
            'latestGoodsReceipt.variances',
        ])->find($orderId);

        if (!$order) {
            return [];
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
                        // Ensure items have quality certificates
                        if (isset($stageData['items']) && is_array($stageData['items'])) {
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
                                if (!isset($item['quality_certificate'])) {
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
                                            $stageData['items'][$key]['quality_certificate'] = $qualityCert->file_url;
                                            continue;
                                        }
                                    }
                                    
                                    // Try SupplierQualityDocument
                                    $supplierDoc = $qualityDocuments->get($item['item_name']);
                                    if ($supplierDoc && $supplierDoc->file_path) {
                                        $stageData['items'][$key]['quality_certificate'] = str_starts_with($supplierDoc->file_path, 'http') 
                                            ? $supplierDoc->file_path 
                                            : asset('storage/' . $supplierDoc->file_path);
                                    }
                                }
                            }
                        }
                        break;
                    
                    case 'delivered':
                        // Ensure invoice is included if available
                        if (!isset($stageData['invoice_file'])) {
                            $latestReceipt = $order->latestGoodsReceipt;
                            $invoice = $latestReceipt?->invoice;
                            
                            if ($invoice) {
                                $stageData['invoice_file'] = FileResource::makeOrNull($invoice)?->toArray(request());
                            } else {
                                // Try SupplierInvoice
                                $supplierInvoice = \Modules\Supplier\Models\SupplierInvoice::where('order_id', $order->id)
                                    ->latest()
                                    ->first();
                                
                                if ($supplierInvoice) {
                                    $stageData['invoice_file'] = FileResource::makeOrNull([
                                        'id' => (string) $supplierInvoice->id,
                                        'file_path' => $supplierInvoice->file_path,
                                        'created_at' => $supplierInvoice->created_at,
                                    ])?->toArray(request());
                                }
                            }
                        }
                        break;
                }
                
                return $stageData;
            })
            ->toArray();

        return $stages;
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
                $itemData['quality_certificate'] = $qualityCert->file_url;
            } else {
                // Try to get from SupplierQualityDocument
                $supplierDoc = $qualityDocuments->get($item->item_name);
                if ($supplierDoc && $supplierDoc->file_path) {
                    $itemData['quality_certificate'] = str_starts_with($supplierDoc->file_path, 'http') 
                        ? $supplierDoc->file_path 
                        : asset('storage/' . $supplierDoc->file_path);
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

        return [
            'status' => 'out_for_delivery',
            'arrival_time' => $order->expected_delivery_at?->format('Y-m-d H:i:s'),
            'driver_details' => [
                'name' => $order->driver_name ?? $deliveryData['driver_name'] ?? null,
                'photo' => $order->driver_photo ?? null,
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
        // Load latest receipt with invoice relationship
        if (!$order->relationLoaded('latestGoodsReceipt')) {
            $order->load('latestGoodsReceipt.invoice');
        } else {
            $order->latestGoodsReceipt?->loadMissing('invoice');
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
        }

        return $stageData;
    }

    /**
     * Prepare order confirmation stage data
     */
    private function prepareOrderConfirmationStageData(PurchaseOrder $order, $latestReceipt): array
    {
        $invoice = $latestReceipt->invoice;

        return [
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
}
