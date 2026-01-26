<?php

namespace Modules\Supplier\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Services\OrderTrackingService;
use Modules\Supplier\Models\DeliveryProof;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierQualityDocument;

class OrderFulfillmentService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly OrderTrackingService $trackingService
    ) {}

    /**
     * Start order preparation
     */
    public function startPreparation(PurchaseOrder $order, Supplier $supplier, array $data = []): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        if ($order->status !== OrderStatus::CONFIRMED) {
            throw new \Exception('Order must be confirmed before starting preparation');
        }

        return DB::transaction(function () use ($order, $data, $supplier) {
            // Update order status
            $order->update([
                'status' => OrderStatus::PREPARING,
                'preparation_started_at' => now(),
            ]);

            // Update items status and upload files
            if (!empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $item = PurchaseOrderItem::where('id', $itemData['id'])
                        ->where('purchase_order_id', $order->id)
                        ->first();

                    if ($item) {
                        // Update item status to preparing
                        if ($item->status === OrderItemStatus::CONFIRMED || 
                            $item->status === OrderItemStatus::PENDING ||
                            $item->status->isConfirmed()) {
                            $item->update([
                                'status' => OrderItemStatus::PREPARING,
                            ]);
                        }

                        // Upload file for this item if provided
                        if (isset($itemData['file']) && is_object($itemData['file']) && method_exists($itemData['file'], 'isValid') && $itemData['file']->isValid()) {
                            $filePath = $itemData['file']->store('supplier/order-items', 'public');
                            $fileExtension = $itemData['file']->getClientOriginalExtension();

                            SupplierQualityDocument::create([
                                'supplier_id' => $supplier->id,
                                'order_id' => $order->id,
                                'document_type' => 'certificate',
                                'title' => 'Item Document - ' . $item->item_name,
                                'file_path' => $filePath,
                                'file_name' => $itemData['file']->getClientOriginalName(),
                                'file_type' => $fileExtension,
                            ]);
                        }
                    }
                }
            }

            // Save tracking stage data
            $this->trackingService->savePreparingStage($order, $data['items'] ?? []);

            $this->notificationService->notifyOrderStatusChanged($order, 'preparing');

            return $order->fresh();
        });
    }

    /**
     * Update preparation progress
     */
    public function updatePreparation(PurchaseOrder $order, Supplier $supplier, array $data): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        if ($order->status !== OrderStatus::PREPARING) {
            throw new \Exception('Order must be in preparing status');
        }

        $order->update([
            'message' => $data['progress_update'] ?? $order->message,
            'expected_delivery_at' => $data['estimated_completion_time'] ?? $order->expected_delivery_at,
        ]);

        return $order->fresh();
    }

    /**
     * Start delivery
     */
    public function startDelivery(PurchaseOrder $order, Supplier $supplier, array $data): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        // Allow starting delivery if order is in PREPARING or DELAYED status
        if (!in_array($order->status, [OrderStatus::PREPARING, OrderStatus::DELAYED])) {
            throw new \Exception('Order must be in preparing or delayed status before starting delivery');
        }

        return DB::transaction(function () use ($order, $data, $supplier) {
            // Update order status
            $updateData = [
                'status' => OrderStatus::ON_THE_WAY,
                'dispatched_at' => now(),
                'driver_name' => $data['driver_name'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'expected_delivery_at' => $data['expected_delivery_at'] ?? null,
                'message' => $data['notes'] ?? null,
            ];

            // Handle driver photo upload
            if (isset($data['driver_photo']) && is_object($data['driver_photo']) && method_exists($data['driver_photo'], 'isValid') && $data['driver_photo']->isValid()) {
                $photoPath = $data['driver_photo']->store('supplier/drivers', 'public');
                $updateData['driver_photo'] = $photoPath;
            }

            $order->update($updateData);

            // Create or update GoodsReceipt with delivery_address
            $receipt = GoodsReceipt::where('purchase_order_id', $order->id)
                ->where('status', 'draft')
                ->first();

            if (!$receipt) {
                // Create new receipt if doesn't exist
                $receipt = GoodsReceipt::create([
                    'purchase_order_id' => $order->id,
                    'branch_id' => $order->branch_id,
                    'received_by' => $order->requested_by, // Will be updated when branch receives
                    'status' => 'draft',
                    'driver_name' => $data['driver_name'] ?? null,
                    'driver_contact' => null, // Will be updated later
                    'vehicle_number' => $data['vehicle_number'] ?? null,
                    'delivery_address' => $data['delivery_address'] ?? null,
                    'delivery_notes' => $data['notes'] ?? null,
                ]);
            } else {
                // Update existing receipt with delivery details
                $receipt->update([
                    'driver_name' => $data['driver_name'] ?? $receipt->driver_name,
                    'vehicle_number' => $data['vehicle_number'] ?? $receipt->vehicle_number,
                    'delivery_address' => $data['delivery_address'] ?? $receipt->delivery_address,
                    'delivery_notes' => $data['notes'] ?? $receipt->delivery_notes,
                ]);
            }

            // Update items status to on_the_way
            // Include delayed_approved items (approved delays can continue delivery)
            $order->items()
                ->whereIn('status', [
                    OrderItemStatus::PREPARING,
                    OrderItemStatus::CONFIRMED,
                    OrderItemStatus::DELAYED_APPROVED, // Approved delays can continue
                ])
                ->update(['status' => OrderItemStatus::ON_THE_WAY]);

            // Save tracking stage data
            $this->trackingService->saveOutForDeliveryStage($order, $data);

            $this->notificationService->notifyOrderStatusChanged($order, 'on_the_way');

            return $order->fresh();
        });
    }

    /**
     * Report delivery delay
     */
    public function reportDelay(PurchaseOrder $order, Supplier $supplier, array $data): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        // Allow delay reporting in both PREPARING and ON_THE_WAY statuses
        if (!in_array($order->status, [OrderStatus::PREPARING, OrderStatus::ON_THE_WAY])) {
            throw new \Exception('Order must be in preparing or delivery status to report delay');
        }

        return DB::transaction(function () use ($order, $data) {
            // Store new expected delivery date as string based on type
            $newExpectedDeliveryAt = null;

            if ($data['new_expected_delivery_date_type'] === 'today') {
                // Use today's date with new_time (store as string)
                $today = Carbon::today()->format('Y-m-d');
                $newExpectedDeliveryAt = $today . ' ' . $data['new_time'];
            } elseif ($data['new_expected_delivery_date_type'] === 'custom') {
                // Extract date only from new_date (handle ISO format like 2026-01-12T00:00:00.000)
                $dateString = $data['new_date'];
                // If it's an ISO format, extract only the date part (before 'T')
                if (strpos($dateString, 'T') !== false) {
                    $dateString = explode('T', $dateString)[0];
                }
                // Use custom date and time (store as string)
                $newExpectedDeliveryAt = $dateString . ' ' . $data['new_time'];
            }

            $updateData = [
                'status' => OrderStatus::DELAYED,
                'delay_reason' => $data['message'],
                'expected_delivery_at' => $newExpectedDeliveryAt,
                'message' => $data['message'],
            ];

            // Handle photo upload if provided
            if (isset($data['photo']) && is_object($data['photo']) && method_exists($data['photo'], 'isValid') && $data['photo']->isValid()) {
                $photoPath = $data['photo']->store('supplier/delays', 'public');
                // Store photo path in delay_reason or create a separate field if needed
                // For now, we'll store it in a JSON format in delay_reason or message
                $updateData['delay_reason'] = json_encode([
                    'message' => $data['message'],
                    'photo' => $photoPath,
                ]);
            }

            $order->update($updateData);

            // Update items status to delayed_supplier (supplier is reporting the delay)
            $order->items()
                ->whereIn('status', [OrderItemStatus::CONFIRMED, OrderItemStatus::PREPARING, OrderItemStatus::ON_THE_WAY, OrderItemStatus::DELAYED_APPROVED])
                ->update(['status' => OrderItemStatus::DELAYED_SUPPLIER]);

            $this->notificationService->notifyOrderStatusChanged($order, 'delayed');

            return $order->fresh();
        });
    }

    /**
     * Complete delivery
     */
    public function completeDelivery(PurchaseOrder $order, Supplier $supplier, array $data = []): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        if (!in_array($order->status, [OrderStatus::ON_THE_WAY, OrderStatus::DELAYED])) {
            throw new \Exception('Order must be out for delivery or delayed');
        }

        return DB::transaction(function () use ($order, $data, $supplier) {
            $updateData = [
                'status' => OrderStatus::DELIVERED,
                'received_at' => now(),
                'actual_delivery_at' => now(),
            ];

            // Handle delivery photos upload
            $deliveryPhotos = [];
            if (!empty($data['delivery_photos'])) {
                foreach ($data['delivery_photos'] as $photo) {
                    if ($photo && is_object($photo) && method_exists($photo, 'isValid') && $photo->isValid()) {
                        $photoPath = $photo->store('supplier/deliveries/photos', 'public');
                        $deliveryPhotos[] = $photoPath;
                    }
                }
                $updateData['delivery_photos'] = $deliveryPhotos;
            }

            $order->update($updateData);

            // Update items status to delivered (all items that are not already delivered, cancelled, or rejected)
            // Include delayed_approved items (approved delays can be delivered)
            // Exclude delayed_supplier and delayed_branch (need approval first)
            $order->items()
                ->whereNotIn('status', [
                    OrderItemStatus::DELIVERED,
                    OrderItemStatus::CANCELLED,
                    OrderItemStatus::CANCELLED_BY_BRANCH,
                    OrderItemStatus::CANCELLED_BY_SUPPLIER,
                    OrderItemStatus::CANCELED_MODIFICATION,
                    OrderItemStatus::CANCELLED_DELAYED,
                    OrderItemStatus::REJECTED,
                    OrderItemStatus::DELAYED_SUPPLIER, // Need branch approval first
                    OrderItemStatus::DELAYED_BRANCH, // Need branch approval first
                ])
                ->update(['status' => OrderItemStatus::DELIVERED]);

            // Create delivery proof record
            DeliveryProof::create([
                'purchase_order_id' => $order->id,
                'recipient_name' => null,
                'recipient_signature' => null,
                'delivery_photos' => $deliveryPhotos,
                'condition_confirmation' => null,
                'acknowledgment_received_at' => now(),
            ]);

            // Save tracking stage data
            $this->trackingService->saveDeliveredStage($order);

            $this->notificationService->notifyOrderStatusChanged($order, 'delivered');

            return $order->fresh();
        });
    }

    /**
     * Submit invoice
     */
    public function submitInvoice(PurchaseOrder $order, Supplier $supplier, array $data): \Modules\Supplier\Models\SupplierInvoice
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        return DB::transaction(function () use ($order, $supplier, $data) {
            $invoice = \Modules\Supplier\Models\SupplierInvoice::create([
                'supplier_id' => $supplier->id,
                'order_id' => $order->id,
                'invoice_number' => $data['invoice_number'] ?? 'INV-' . strtoupper(uniqid()),
                'invoice_date' => $data['invoice_date'] ?? now(),
                'due_date' => $data['due_date'] ?? now()->addDays(30),
                'subtotal' => $data['subtotal'] ?? $order->subtotal,
                'tax_rate' => $data['tax_rate'] ?? 15.00,
                'tax_amount' => $data['tax_amount'] ?? ($data['subtotal'] ?? $order->subtotal) * 0.15,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'total_amount' => $data['total_amount'] ?? $order->total_amount,
                'items' => $data['items'] ?? $order->items->toArray(),
                'file_path' => $data['file_path'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->notificationService->notifyInvoiceSubmitted($invoice);

            return $invoice;
        });
    }
}

