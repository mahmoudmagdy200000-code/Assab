<?php

namespace Modules\Supplier\Services;

use Illuminate\Support\Facades\Log;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierNotification;

class NotificationService
{
    /**
     * Send order received notification
     */
    public function notifyOrderReceived(PurchaseOrder $order): void
    {
        $supplier = $order->supplier;
        if (!$supplier) {
            return;
        }

        $channels = $supplier->notification_preferences['channels'] ?? ['app', 'email'];

        foreach ($channels as $channel) {
            $this->sendNotification(
                supplier: $supplier,
                type: 'order_received',
                title: 'New Order Received',
                message: "You have received a new order: {$order->order_number}",
                data: [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'branch_name' => $order->branch->name ?? null,
                    'total_amount' => $order->total_amount,
                ],
                channel: $channel,
                relatedOrderId: $order->id
            );
        }
    }

    /**
     * Notify order accepted
     */
    public function notifyOrderAccepted(PurchaseOrder $order): void
    {
        // This notification goes to branch manager, not supplier
        // Implementation would be in Branch module
        Log::info("Order {$order->order_number} accepted by supplier");
    }

    /**
     * Notify order rejected
     */
    public function notifyOrderRejected(PurchaseOrder $order): void
    {
        // This notification goes to branch manager, not supplier
        Log::info("Order {$order->order_number} rejected by supplier");
    }

    /**
     * Notify order modification requested
     */
    public function notifyOrderModificationRequested(PurchaseOrder $order, array $data): void
    {
        // This notification goes to branch manager
        Log::info("Order {$order->order_number} modification requested by supplier");
    }

    /**
     * Notify order status changed
     */
    public function notifyOrderStatusChanged(PurchaseOrder $order, string $status): void
    {
        $supplier = $order->supplier;
        if (!$supplier) {
            return;
        }

        $statusLabels = [
            'preparing' => 'Order Preparation Started',
            'on_the_way' => 'Order Out for Delivery',
            'delayed' => 'Delivery Delayed',
            'delivered' => 'Order Delivered',
        ];

        $this->sendNotification(
            supplier: $supplier,
            type: 'order_status_changed',
            title: $statusLabels[$status] ?? 'Order Status Updated',
            message: "Order {$order->order_number} status has been updated to: {$status}",
            data: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $status,
            ],
            channel: 'app',
            relatedOrderId: $order->id
        );
    }

    /**
     * Notify invoice submitted
     */
    public function notifyInvoiceSubmitted(\Modules\Supplier\Models\SupplierInvoice $invoice): void
    {
        // This notification goes to branch manager/admin
        Log::info("Invoice {$invoice->invoice_number} submitted by supplier");
    }

    /**
     * Send notification through specified channel
     */
    private function sendNotification(
        Supplier $supplier,
        string $type,
        string $title,
        string $message,
        array $data = [],
        string $channel = 'app',
        ?string $relatedOrderId = null
    ): void {
        // Create in-app notification
        if ($channel === 'app') {
            SupplierNotification::create([
                'supplier_id' => $supplier->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'channel' => $channel,
                'related_order_id' => $relatedOrderId,
                'sent_at' => now(),
            ]);
        }

        // Send email
        if ($channel === 'email' && $supplier->email) {
            // TODO: Implement email sending
            Log::info("Sending email notification to supplier {$supplier->email}: {$title}");
        }

        // Send SMS
        if ($channel === 'sms' && $supplier->phone) {
            // TODO: Implement SMS sending
            Log::info("Sending SMS notification to supplier {$supplier->phone}: {$title}");
        }

        // Send WhatsApp
        if ($channel === 'whatsapp' && $supplier->phone) {
            // TODO: Implement WhatsApp sending
            Log::info("Sending WhatsApp notification to supplier {$supplier->phone}: {$title}");
        }
    }

    /**
     * Get unread notifications count
     */
    public function getUnreadCount(Supplier $supplier): int
    {
        return SupplierNotification::where('supplier_id', $supplier->id)
            ->where('is_read', false)
            ->count();
    }

    /**
     * Mark notifications as read
     */
    public function markAsRead(Supplier $supplier, ?string $notificationId = null): void
    {
        $query = SupplierNotification::where('supplier_id', $supplier->id)
            ->where('is_read', false);

        if ($notificationId) {
            $query->where('id', $notificationId);
        }

        $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }
}

