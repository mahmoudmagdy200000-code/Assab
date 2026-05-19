<?php

namespace Modules\Purchase\Enums;

enum TimelineEventType: string
{
    // Order lifecycle
    case ORDER_CREATED = 'order_created';
    case ORDER_SUBMITTED = 'order_submitted';
    case ORDER_VIEWED = 'order_viewed';
    case ORDER_CONFIRMED = 'order_confirmed';
    case ORDER_REJECTED = 'order_rejected';
    case ORDER_CANCELED = 'order_canceled';
    case ORDER_MODIFIED = 'order_modified';
    
    // Approval flow
    case APPROVAL_REQUESTED = 'approval_requested';
    case APPROVAL_GRANTED = 'approval_granted';
    case APPROVAL_DENIED = 'approval_denied';
    case PARTIAL_APPROVAL = 'partial_approval';
    
    // Preparation & Delivery
    case PREPARATION_STARTED = 'preparation_started';
    case QUALITY_CERTIFICATE_UPLOADED = 'quality_certificate_uploaded';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case DELIVERY_DELAYED = 'delivery_delayed';
    case DELIVERED = 'delivered';
    
    // Receiving
    case INSPECTION_STARTED = 'inspection_started';
    case INSPECTION_COMPLETED = 'inspection_completed';
    case VARIANCE_DETECTED = 'variance_detected';
    case GOODS_RECEIVED = 'goods_received';
    
    // Documents
    case INVOICE_UPLOADED = 'invoice_uploaded';
    case DOCUMENT_ATTACHED = 'document_attached';
    
    // Returns
    case RETURN_CREATED = 'return_created';
    case RETURN_SUBMITTED = 'return_submitted';
    case RETURN_APPROVED = 'return_approved';
    case RETURN_REJECTED = 'return_rejected';
    case RETURN_ESCALATED = 'return_escalated';
    case RETURN_RESOLVED = 'return_resolved';
    case RETURN_ESCALATION_APPROVED = 'return_escalation_approved';
    case RETURN_ESCALATION_REJECTED = 'return_escalation_rejected';
    
    // Variance
    case COMPENSATORY_ORDER_CREATED = 'compensatory_order_created';
    case INVOICE_DEDUCTED = 'invoice_deducted';
    case VARIANCE_ACCEPTED = 'variance_accepted';

    public function label(): string
    {
        return match($this) {
            self::ORDER_CREATED => 'Order Created',
            self::ORDER_SUBMITTED => 'Order Submitted',
            self::ORDER_VIEWED => 'Order Viewed',
            self::ORDER_CONFIRMED => 'Order Confirmed',
            self::ORDER_REJECTED => 'Order Rejected',
            self::ORDER_CANCELED => 'Order Canceled',
            self::ORDER_MODIFIED => 'Order Modified',
            self::APPROVAL_REQUESTED => 'Approval Requested',
            self::APPROVAL_GRANTED => 'Approval Granted',
            self::APPROVAL_DENIED => 'Approval Denied',
            self::PARTIAL_APPROVAL => 'Partial Approval',
            self::PREPARATION_STARTED => 'Preparation Started',
            self::QUALITY_CERTIFICATE_UPLOADED => 'Quality Certificate Uploaded',
            self::OUT_FOR_DELIVERY => 'Out for Delivery',
            self::DELIVERY_DELAYED => 'Delivery Delayed',
            self::DELIVERED => 'Delivered',
            self::INSPECTION_STARTED => 'Inspection Started',
            self::INSPECTION_COMPLETED => 'Inspection Completed',
            self::VARIANCE_DETECTED => 'Variance Detected',
            self::GOODS_RECEIVED => 'Goods Received',
            self::INVOICE_UPLOADED => 'Invoice Uploaded',
            self::DOCUMENT_ATTACHED => 'Document Attached',
            self::RETURN_CREATED => 'Return Created',
            self::RETURN_SUBMITTED => 'Return Submitted',
            self::RETURN_APPROVED => 'Return Approved',
            self::RETURN_REJECTED => 'Return Rejected',
            self::RETURN_ESCALATED => 'Return Escalated',
            self::RETURN_RESOLVED => 'Return Resolved',
            self::RETURN_ESCALATION_APPROVED => 'Escalation Approved',
            self::RETURN_ESCALATION_REJECTED => 'Escalation Rejected',
            self::COMPENSATORY_ORDER_CREATED => 'Compensatory Order Created',
            self::INVOICE_DEDUCTED => 'Invoice Deducted',
            self::VARIANCE_ACCEPTED => 'Variance Accepted',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::ORDER_CREATED, self::ORDER_SUBMITTED => 'plus-circle',
            self::ORDER_VIEWED => 'eye',
            self::ORDER_CONFIRMED, self::APPROVAL_GRANTED => 'check-circle',
            self::ORDER_REJECTED, self::APPROVAL_DENIED => 'x-circle',
            self::ORDER_CANCELED => 'ban',
            self::ORDER_MODIFIED => 'edit',
            self::APPROVAL_REQUESTED => 'clock',
            self::PARTIAL_APPROVAL => 'check',
            self::PREPARATION_STARTED => 'package',
            self::QUALITY_CERTIFICATE_UPLOADED => 'file-check',
            self::OUT_FOR_DELIVERY => 'truck',
            self::DELIVERY_DELAYED => 'alert-triangle',
            self::DELIVERED => 'check-square',
            self::INSPECTION_STARTED, self::INSPECTION_COMPLETED => 'search',
            self::VARIANCE_DETECTED => 'alert-circle',
            self::GOODS_RECEIVED => 'box',
            self::INVOICE_UPLOADED, self::DOCUMENT_ATTACHED => 'file',
            self::RETURN_CREATED, self::RETURN_SUBMITTED => 'rotate-ccw',
            self::RETURN_APPROVED => 'thumbs-up',
            self::RETURN_REJECTED => 'thumbs-down',
            self::RETURN_ESCALATED => 'arrow-up',
            self::RETURN_RESOLVED => 'check-circle',
            self::RETURN_ESCALATION_APPROVED => 'check-circle',
            self::RETURN_ESCALATION_REJECTED => 'x-circle',
            self::COMPENSATORY_ORDER_CREATED => 'refresh-cw',
            self::INVOICE_DEDUCTED => 'minus-circle',
            self::VARIANCE_ACCEPTED => 'check',
        };
    }
}

