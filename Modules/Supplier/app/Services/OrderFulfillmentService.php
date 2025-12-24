<?php

namespace Modules\Supplier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierQualityDocument;

class OrderFulfillmentService
{
    public function __construct(
        private readonly NotificationService $notificationService
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

        return DB::transaction(function () use ($order, $data) {
            $order->update([
                'status' => OrderStatus::PREPARING,
                'preparation_started_at' => now(),
                'message' => $data['message'] ?? null,
            ]);

            // Upload quality documents if provided
            if (!empty($data['quality_documents'])) {
                foreach ($data['quality_documents'] as $document) {
                    SupplierQualityDocument::create([
                        'supplier_id' => $supplier->id,
                        'order_id' => $order->id,
                        'document_type' => $document['type'] ?? 'certificate',
                        'title' => $document['title'] ?? 'Quality Certificate',
                        'file_path' => $document['file_path'],
                        'file_name' => $document['file_name'],
                        'file_type' => $document['file_type'] ?? 'pdf',
                    ]);
                }
            }

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

        if (!in_array($order->status, [OrderStatus::PREPARING, OrderStatus::CONFIRMED])) {
            throw new \Exception('Order must be preparing or confirmed before starting delivery');
        }

        return DB::transaction(function () use ($order, $data) {
            $order->update([
                'status' => OrderStatus::ON_THE_WAY,
                'dispatched_at' => now(),
                'driver_name' => $data['driver_name'] ?? null,
                'driver_contact' => $data['driver_contact'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'transport_method' => $data['transport_method'] ?? null,
                'estimated_transport_hours' => $data['estimated_transport_hours'] ?? null,
                'expected_delivery_at' => $data['expected_delivery_at'] ?? now()->addHours($data['estimated_transport_hours'] ?? 2),
            ]);

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

        if ($order->status !== OrderStatus::ON_THE_WAY) {
            throw new \Exception('Order must be out for delivery');
        }

        $order->update([
            'status' => OrderStatus::DELAYED,
            'delay_reason' => $data['reason'],
            'expected_delivery_at' => $data['updated_eta'] ?? $order->expected_delivery_at,
            'message' => $data['explanation'] ?? null,
        ]);

        $this->notificationService->notifyOrderStatusChanged($order, 'delayed');

        return $order->fresh();
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

        return DB::transaction(function () use ($order, $data) {
            $order->update([
                'status' => OrderStatus::DELIVERED,
                'received_at' => now(),
                'actual_delivery_at' => now(),
                'message' => $data['delivery_notes'] ?? null,
            ]);

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

