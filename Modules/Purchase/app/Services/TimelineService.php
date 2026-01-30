<?php

namespace Modules\Purchase\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Purchase\Enums\TimelineEventType;
use Modules\Purchase\Models\CompensatoryOrder;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\OrderTimeline;
use Modules\Purchase\Models\PurchaseInvoice;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseVariance;
use Modules\Purchase\Models\ReturnOrder;

class TimelineService
{
    // Order Events
    public function logOrderCreated(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::ORDER_CREATED,
            'Order Created',
            "Order {$order->order_number} was created",
            null,
            $order->status->value
        );
    }

    public function logOrderSubmitted(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::ORDER_SUBMITTED,
            'Order Submitted',
            "Order {$order->order_number} was submitted for processing",
            'draft',
            'pending'
        );
    }

    /**
     * Log order viewed. Only one event per viewer per order (first view only).
     */
    public function logOrderViewed(PurchaseOrder $order, string $viewedBy): OrderTimeline
    {
        $existing = OrderTimeline::query()
            ->where('timelineable_type', PurchaseOrder::class)
            ->where('timelineable_id', $order->id)
            ->where('event_type', TimelineEventType::ORDER_VIEWED)
            ->where('metadata->viewed_by', $viewedBy)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->log(
            $order,
            TimelineEventType::ORDER_VIEWED,
            'Order Viewed',
            'Order was viewed',
            null,
            null,
            ['viewed_by' => $viewedBy]
        );
    }

    public function logOrderConfirmed(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::ORDER_CONFIRMED,
            'Order Confirmed',
            "Order {$order->order_number} was confirmed",
            'pending',
            'confirmed'
        );
    }

    public function logPartialConfirmation(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::PARTIAL_APPROVAL,
            'Partial Confirmation',
            "Order {$order->order_number} was partially confirmed",
            'pending',
            'partial_confirmation'
        );
    }

    public function logOrderRejected(PurchaseOrder $order, string $reason): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::ORDER_REJECTED,
            'Order Rejected',
            "Order was rejected: {$reason}",
            $order->status->value,
            'rejected',
            ['reason' => $reason]
        );
    }

    public function logOrderCanceled(PurchaseOrder $order, ?string $reason = null): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::ORDER_CANCELED,
            'Order Canceled',
            $reason ? "Order was canceled: {$reason}" : "Order was canceled",
            $order->status->value,
            'cancelled',
            ['reason' => $reason]
        );
    }

    public function logOrderModified(PurchaseOrder $order, array $modifications): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::ORDER_MODIFIED,
            'Order Modified',
            "Order items were modified",
            null,
            'pending_approval',
            ['modifications' => $modifications]
        );
    }

    public function logModificationsApproved(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::APPROVAL_GRANTED,
            'Modifications Approved',
            "Order modifications were approved",
            'pending_approval',
            'confirmed'
        );
    }

    public function logModificationsRejected(PurchaseOrder $order, string $reason): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::APPROVAL_DENIED,
            'Modifications Rejected',
            "Order modifications were rejected: {$reason}",
            'pending_approval',
            'cancelled',
            ['reason' => $reason]
        );
    }

    public function logItemApproved(PurchaseOrder $order, PurchaseOrderItem $item): OrderTimeline
    {
        $approvalType = $item->approval_type;
        $message = match ($approvalType) {
            'partial' => "Item '{$item->item_name}' partial quantity approved",
            'time_change' => "Item '{$item->item_name}' delivery time change approved",
            'alternative' => "Item '{$item->item_name}' alternative product approved",
            default => "Item '{$item->item_name}' request approved",
        };

        return $this->log(
            $order,
            TimelineEventType::APPROVAL_GRANTED,
            'Item Request Approved',
            $message,
            'needs_approval',
            'confirmed',
            [
                'item_id' => $item->id,
                'item_name' => $item->item_name,
                'approval_type' => $approvalType,
                'approval_data' => $item->approval_data,
            ]
        );
    }

    public function logItemRejected(PurchaseOrder $order, PurchaseOrderItem $item, ?string $reason = null): OrderTimeline
    {
        $approvalType = $item->approval_type;
        $message = match ($approvalType) {
            'partial' => "Item '{$item->item_name}' partial quantity request rejected",
            'time_change' => "Item '{$item->item_name}' delivery time change rejected",
            'alternative' => "Item '{$item->item_name}' alternative product rejected",
            default => "Item '{$item->item_name}' request rejected",
        };

        if ($reason) {
            $message .= ": {$reason}";
        }

        return $this->log(
            $order,
            TimelineEventType::APPROVAL_DENIED,
            'Item Request Rejected',
            $message,
            'needs_approval',
            'rejected',
            [
                'item_id' => $item->id,
                'item_name' => $item->item_name,
                'approval_type' => $approvalType,
                'reason' => $reason,
            ]
        );
    }

    /**
     * Log item delay approval
     */
    public function logItemDelayApproved(PurchaseOrder $order, PurchaseOrderItem $item): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::APPROVAL_GRANTED,
            'Item Delay Approved',
            "Item '{$item->item_name}' delay request approved by branch manager",
            $item->status->value,
            'delayed_approved',
            [
                'item_id' => $item->id,
                'item_name' => $item->item_name,
                'approval_type' => 'delay',
            ]
        );
    }

    /**
     * Log item delay rejection
     */
    public function logItemDelayRejected(PurchaseOrder $order, PurchaseOrderItem $item, ?string $reason = null): OrderTimeline
    {
        $message = "Item '{$item->item_name}' delay request rejected by branch manager";
        if ($reason) {
            $message .= ": {$reason}";
        }

        return $this->log(
            $order,
            TimelineEventType::APPROVAL_DENIED,
            'Item Delay Rejected',
            $message,
            $item->status->value,
            'cancelled_delayed',
            [
                'item_id' => $item->id,
                'item_name' => $item->item_name,
                'approval_type' => 'delay',
                'reason' => $reason,
            ]
        );
    }

    public function logPreparationStarted(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::PREPARATION_STARTED,
            'Preparation Started',
            "Order preparation has started",
            'confirmed',
            'preparing'
        );
    }

    public function logDeliveryDelayed(PurchaseOrder $order, string $reason): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::DELIVERY_DELAYED,
            'Delivery Delayed',
            "Delivery was delayed: {$reason}",
            $order->status->value,
            'delayed',
            ['reason' => $reason, 'new_expected_delivery' => $order->expected_delivery_at?->format('Y-m-d H:i')]
        );
    }

    public function logOrderDelivered(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::DELIVERED,
            'Order Delivered',
            "Order was delivered",
            'on_the_way',
            'delivered'
        );
    }

    public function logOrderClosed(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::ORDER_CONFIRMED,
            'Order Closed',
            "Order was closed successfully",
            $order->status->value,
            'closed'
        );
    }

    /**
     * Log receiving started on the order (so it appears in order timeline).
     */
    public function logReceivingStarted(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::INSPECTION_STARTED,
            'Receiving Started',
            "Goods receiving started for order {$order->order_number}",
            null,
            null
        );
    }

    // Inspection Events
    public function logInspectionStarted(GoodsReceipt $receipt): OrderTimeline
    {
        return $this->log(
            $receipt,
            TimelineEventType::INSPECTION_STARTED,
            'Inspection Started',
            "Goods inspection started for receipt {$receipt->receipt_number}"
        );
    }

    public function logInspectionCompleted(GoodsReceipt $receipt): OrderTimeline
    {
        return $this->log(
            $receipt,
            TimelineEventType::INSPECTION_COMPLETED,
            'Inspection Completed',
            "Goods inspection completed",
            'in_progress',
            $receipt->status,
            [
                'items_received' => $receipt->total_items_received,
                'variances' => $receipt->quantity_variances + $receipt->quality_variances,
            ]
        );
    }

    public function logInvoiceUploaded(GoodsReceipt $receipt, PurchaseInvoice $invoice): OrderTimeline
    {
        return $this->log(
            $receipt,
            TimelineEventType::INVOICE_UPLOADED,
            'Invoice Uploaded',
            "Invoice {$invoice->invoice_number} was uploaded",
            null,
            null,
            [
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->total_amount,
            ]
        );
    }

    // Variance Events
    public function logVarianceDetected(PurchaseVariance $variance): OrderTimeline
    {
        return $this->log(
            $variance,
            TimelineEventType::VARIANCE_DETECTED,
            'Variance Detected',
            "Variance detected for {$variance->item_name}",
            null,
            'pending',
            [
                'type' => $variance->variance_type->value,
                'amount' => $variance->variance_amount,
            ]
        );
    }

    public function logVarianceAccepted(PurchaseVariance $variance): OrderTimeline
    {
        return $this->log(
            $variance,
            TimelineEventType::VARIANCE_ACCEPTED,
            'Variance Accepted',
            "Variance was accepted as is"
        );
    }

    public function logCompensatoryOrderCreated(PurchaseVariance $variance, CompensatoryOrder $compensatory): OrderTimeline
    {
        return $this->log(
            $variance,
            TimelineEventType::COMPENSATORY_ORDER_CREATED,
            'Compensatory Order Created',
            "Compensatory order {$compensatory->order_number} was created",
            null,
            null,
            ['compensatory_order_id' => $compensatory->id]
        );
    }

    public function logInvoiceDeducted(PurchaseVariance $variance, float $amount): OrderTimeline
    {
        return $this->log(
            $variance,
            TimelineEventType::INVOICE_DEDUCTED,
            'Invoice Deducted',
            "Amount {$amount} was deducted from invoice",
            null,
            null,
            ['amount' => $amount, 'reason' => $variance->deduction_reason]
        );
    }

    public function logVarianceApproved(PurchaseVariance $variance): OrderTimeline
    {
        return $this->log(
            $variance,
            TimelineEventType::APPROVAL_GRANTED,
            'Variance Approved',
            "Variance claim was approved by supplier"
        );
    }

    public function logVarianceRejected(PurchaseVariance $variance, string $reason): OrderTimeline
    {
        return $this->log(
            $variance,
            TimelineEventType::APPROVAL_DENIED,
            'Variance Rejected',
            "Variance claim was rejected: {$reason}",
            null,
            'supplier_rejected',
            ['reason' => $reason]
        );
    }

    public function logRejectionAccepted($model): OrderTimeline
    {
        return $this->log(
            $model,
            TimelineEventType::ORDER_CONFIRMED,
            'Rejection Accepted',
            "Rejection was accepted by branch manager"
        );
    }

    public function logVarianceEscalated(PurchaseVariance $variance, string $reason): OrderTimeline
    {
        return $this->log(
            $variance,
            TimelineEventType::RETURN_ESCALATED,
            'Variance Escalated',
            "Variance was escalated to Brand Owner",
            null,
            'escalated',
            ['reason' => $reason]
        );
    }

    public function logVarianceResolved(PurchaseVariance $variance): OrderTimeline
    {
        return $this->log(
            $variance,
            TimelineEventType::RETURN_RESOLVED,
            'Variance Resolved',
            "Variance was resolved"
        );
    }

    // Return Events
    public function logReturnCreated(ReturnOrder $return): OrderTimeline
    {
        return $this->log(
            $return,
            TimelineEventType::RETURN_CREATED,
            'Return Created',
            "Return order {$return->return_number} was created"
        );
    }

    public function logReturnSubmitted(ReturnOrder $return): OrderTimeline
    {
        return $this->log(
            $return,
            TimelineEventType::RETURN_SUBMITTED,
            'Return Submitted',
            "Return order was submitted for review",
            'draft',
            'pending'
        );
    }

    public function logReturnApproved(ReturnOrder $return): OrderTimeline
    {
        return $this->log(
            $return,
            TimelineEventType::RETURN_APPROVED,
            'Return Approved',
            "Return order was approved",
            'pending',
            'approved'
        );
    }

    public function logReturnRejected(ReturnOrder $return, string $reason): OrderTimeline
    {
        return $this->log(
            $return,
            TimelineEventType::RETURN_REJECTED,
            'Return Rejected',
            "Return order was rejected: {$reason}",
            'pending',
            'rejected',
            ['reason' => $reason]
        );
    }

    public function logReturnEscalated(ReturnOrder $return, string $reason): OrderTimeline
    {
        return $this->log(
            $return,
            TimelineEventType::RETURN_ESCALATED,
            'Return Escalated',
            "Return was escalated to Brand Owner",
            null,
            'escalated',
            ['reason' => $reason]
        );
    }

    public function logReturnResolved(ReturnOrder $return, string $resolutionType): OrderTimeline
    {
        return $this->log(
            $return,
            TimelineEventType::RETURN_RESOLVED,
            'Return Resolved',
            "Return was resolved with: {$resolutionType}",
            null,
            'resolved',
            ['resolution_type' => $resolutionType]
        );
    }

    /**
     * Log preparing stage
     */
    public function logPreparing(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::PREPARATION_STARTED,
            'Preparing',
            'Order items are being prepared',
            $order->status->value,
            'preparing'
        );
    }

    /**
     * Log quality certificate upload
     */
    public function logQualityCertificate(PurchaseOrder $order, PurchaseOrderItem $item): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::QUALITY_CERTIFICATE_UPLOADED,
            'Quality Certificate Uploaded',
            "Quality certificate uploaded for item {$item->item_name}",
            null,
            null,
            ['item_id' => $item->id, 'item_name' => $item->item_name]
        );
    }

    /**
     * Log out for delivery
     */
    public function logOutForDelivery(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::OUT_FOR_DELIVERY,
            'Out for Delivery',
            'Order is out for delivery',
            'preparing',
            'on_the_way',
            [
                'arrival_time' => $order->expected_delivery_at?->format('Y-m-d H:i:s'),
                'driver_name' => $order->driver_name,
                'driver_photo' => $order->driver_photo,
                'vehicle_number' => $order->vehicle_number,
                'delivery_address' => $order->branch->location ?? null,
                'delivery_notes' => null,
            ]
        );
    }

    /**
     * Log delivered
     */
    public function logDelivered(PurchaseOrder $order): OrderTimeline
    {
        return $this->log(
            $order,
            TimelineEventType::DELIVERED,
            'Delivered',
            'Order has been delivered',
            'on_the_way',
            'delivered'
        );
    }

    /**
     * Log order confirmation
     */
    public function logOrderConfirmation(GoodsReceipt $receipt): OrderTimeline
    {
        return $this->log(
            $receipt,
            TimelineEventType::ORDER_CONFIRMED,
            'Order Confirmed',
            'Order confirmation completed after inspection',
            'delivered',
            'confirmed',
            [
                'items_received' => $receipt->total_items_received,
                'total_amount' => $receipt->received_amount,
            ]
        );
    }

    /**
     * Log variance logged
     */
    public function logVarianceLogged(PurchaseVariance $variance): OrderTimeline
    {
        return $this->log(
            $variance,
            TimelineEventType::VARIANCE_DETECTED,
            'Variance Logged',
            "Variance logged for {$variance->item_name}",
            null,
            'pending',
            [
                'variance_type' => $variance->variance_type->value,
                'amount' => $variance->variance_amount,
            ]
        );
    }

    /**
     * Log supplier contact
     */
    public function logSupplierContact(
        PurchaseOrder $order,
        string $channel,
        ?string $message = null
    ): OrderTimeline {
        $channelLabels = [
            'whatsapp' => 'WhatsApp',
            'sms' => 'SMS',
            'email' => 'Email',
            'in_app' => 'In-App Notification',
        ];

        $channelLabel = $channelLabels[$channel] ?? $channel;

        return $this->log(
            $order,
            TimelineEventType::ORDER_VIEWED,
            'Supplier Contacted',
            "Contacted supplier via {$channelLabel}",
            null,
            null,
            [
                'channel' => $channel,
                'message' => $message,
                'contacted_at' => now()->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Log variance submitted
     */
    public function logVarianceSubmitted(PurchaseVariance $variance): OrderTimeline
    {
        return $this->log(
            $variance,
            TimelineEventType::VARIANCE_DETECTED,
            'Variance Submitted',
            "Variance submitted for {$variance->item_name}",
            null,
            'reported',
            [
                'submitted_by' => auth()->id(),
                'submitted_at' => now()->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Log variance viewed
     */
    public function logVarianceViewed(PurchaseVariance $variance, string $viewedBy): OrderTimeline
    {
        return $this->log(
            $variance,
            TimelineEventType::ORDER_VIEWED,
            'Variance Viewed',
            "Variance was viewed by supplier",
            null,
            null,
            [
                'viewed_by' => $viewedBy,
                'viewed_at' => now()->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Core logging method
     */
    private function log(
        Model $model,
        TimelineEventType $eventType,
        string $title,
        ?string $description = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?array $metadata = null,
        ?array $attachments = null
    ): OrderTimeline {
        return OrderTimeline::log(
            $model,
            $eventType,
            $title,
            $description,
            $oldStatus,
            $newStatus,
            $metadata,
            $attachments
        );
    }
}
