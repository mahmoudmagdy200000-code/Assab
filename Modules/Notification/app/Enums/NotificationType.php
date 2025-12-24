<?php

namespace Modules\Notification\Enums;

enum NotificationType: string
{
    // Shift Management
    case SHIFT_START_REMINDER = 'shift_start_reminder';
    case SHIFT_START_OVERDUE = 'shift_start_overdue';
    case SHIFT_END_REMINDER = 'shift_end_reminder';
    case SHIFT_HANDOVER_PENDING = 'shift_handover_pending';
    case SHIFT_HANDOVER_REQUEST = 'shift_handover_request';
    case SHIFT_HANDOVER_APPROVAL_REQUIRED = 'shift_handover_approval_required';
    case SHIFT_HANDOVER_APPROVED = 'shift_handover_approved';
    case SHIFT_HANDOVER_REJECTED = 'shift_handover_rejected';
    case SHIFT_HANDOVER_VARIANCE = 'shift_handover_variance';
    case SHIFT_HANDOVER_COMPLETED = 'shift_handover_completed';
    case SHIFT_UNAPPROVED_HANDOVER = 'shift_unapproved_handover';

    // Sales and Payment Variance
    case CASH_VARIANCE_HIGH = 'cash_variance_high';
    case CARD_PAYMENT_DISCREPANCY = 'card_payment_discrepancy';
    case DELIVERY_SETTLEMENT_MISMATCH = 'delivery_settlement_mismatch';
    case HIGH_VARIANCE_TREND = 'high_variance_trend';

    // Expense Management
    case EXPENSE_SUBMITTED = 'expense_submitted';
    case EXPENSE_APPROVED = 'expense_approved';
    case EXPENSE_REJECTED = 'expense_rejected';
    case EXPENSE_PRE_APPROVAL_REQUEST = 'expense_pre_approval_request';
    case EXPENSE_LIMIT_EXCEEDED = 'expense_limit_exceeded';

    // Custody Management
    case CUSTODY_REQUEST_CREATED = 'custody_request_created';
    case CUSTODY_REQUEST_APPROVED = 'custody_request_approved';
    case CUSTODY_REQUEST_REJECTED = 'custody_request_rejected';
    case CUSTODY_LOW_BALANCE = 'custody_low_balance';
    case CUSTODY_CASH_TRANSFER = 'custody_cash_transfer';
    case CUSTODY_RECONCILIATION_REMINDER = 'custody_reconciliation_reminder';

    // Financial Compliance
    case VAT_REPORTING_DEADLINE = 'vat_reporting_deadline';
    case TAX_DOCUMENT_REQUIRED = 'tax_document_required';
    case COMPLIANCE_VIOLATION = 'compliance_violation';
    case AUDIT_TRAIL_NOTIFICATION = 'audit_trail_notification';

    // Purchase/Order Management
    case ORDER_CREATED = 'order_created';
    case ORDER_STATUS_CHANGED = 'order_status_changed';
    case ORDER_VARIANCE_DETECTED = 'order_variance_detected';
    case GOODS_RECEIVED = 'goods_received';
    case RETURN_ORDER_SUBMITTED = 'return_order_submitted';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::SHIFT_START_REMINDER,
            self::SHIFT_START_OVERDUE,
            self::SHIFT_END_REMINDER,
            self::SHIFT_HANDOVER_PENDING,
            self::SHIFT_HANDOVER_REQUEST,
            self::SHIFT_HANDOVER_APPROVAL_REQUIRED,
            self::SHIFT_HANDOVER_APPROVED,
            self::SHIFT_HANDOVER_REJECTED,
            self::SHIFT_HANDOVER_VARIANCE,
            self::SHIFT_HANDOVER_COMPLETED,
            self::SHIFT_UNAPPROVED_HANDOVER => NotificationCategory::OPERATIONAL,

            self::CASH_VARIANCE_HIGH,
            self::CARD_PAYMENT_DISCREPANCY,
            self::DELIVERY_SETTLEMENT_MISMATCH,
            self::HIGH_VARIANCE_TREND => NotificationCategory::OPERATIONAL,

            self::EXPENSE_SUBMITTED,
            self::EXPENSE_APPROVED,
            self::EXPENSE_REJECTED,
            self::EXPENSE_PRE_APPROVAL_REQUEST,
            self::EXPENSE_LIMIT_EXCEEDED,
            self::CUSTODY_REQUEST_CREATED,
            self::CUSTODY_REQUEST_APPROVED,
            self::CUSTODY_REQUEST_REJECTED,
            self::CUSTODY_LOW_BALANCE,
            self::CUSTODY_CASH_TRANSFER,
            self::CUSTODY_RECONCILIATION_REMINDER => NotificationCategory::FINANCIAL,

            self::VAT_REPORTING_DEADLINE,
            self::TAX_DOCUMENT_REQUIRED,
            self::COMPLIANCE_VIOLATION,
            self::AUDIT_TRAIL_NOTIFICATION => NotificationCategory::FINANCIAL,

            self::ORDER_CREATED,
            self::ORDER_STATUS_CHANGED,
            self::ORDER_VARIANCE_DETECTED,
            self::GOODS_RECEIVED,
            self::RETURN_ORDER_SUBMITTED => NotificationCategory::OPERATIONAL,
        };
    }

    public function defaultPriority(): NotificationPriority
    {
        return match ($this) {
            self::SHIFT_START_OVERDUE,
            self::SHIFT_UNAPPROVED_HANDOVER,
            self::CASH_VARIANCE_HIGH,
            self::EXPENSE_LIMIT_EXCEEDED,
            self::COMPLIANCE_VIOLATION => NotificationPriority::HIGH,

            self::SHIFT_HANDOVER_APPROVAL_REQUIRED,
            self::SHIFT_HANDOVER_VARIANCE,
            self::CUSTODY_LOW_BALANCE,
            self::ORDER_VARIANCE_DETECTED => NotificationPriority::MEDIUM,

            default => NotificationPriority::LOW,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SHIFT_START_REMINDER => 'Shift Start Reminder',
            self::SHIFT_START_OVERDUE => 'Shift Start Overdue',
            self::SHIFT_END_REMINDER => 'Shift End Reminder',
            self::SHIFT_HANDOVER_PENDING => 'Handover Pending',
            self::SHIFT_HANDOVER_REQUEST => 'Handover Request',
            self::SHIFT_HANDOVER_APPROVAL_REQUIRED => 'Handover Approval Required',
            self::SHIFT_HANDOVER_APPROVED => 'Handover Approved',
            self::SHIFT_HANDOVER_REJECTED => 'Handover Rejected',
            self::SHIFT_HANDOVER_VARIANCE => 'Handover Variance Detected',
            self::SHIFT_HANDOVER_COMPLETED => 'Handover Completed',
            self::SHIFT_UNAPPROVED_HANDOVER => 'Unapproved Handover Escalation',
            self::CASH_VARIANCE_HIGH => 'Cash Variance Alert',
            self::CARD_PAYMENT_DISCREPANCY => 'Card Payment Discrepancy',
            self::DELIVERY_SETTLEMENT_MISMATCH => 'Delivery Settlement Mismatch',
            self::HIGH_VARIANCE_TREND => 'High Variance Trend',
            self::EXPENSE_SUBMITTED => 'Expense Submitted',
            self::EXPENSE_APPROVED => 'Expense Approved',
            self::EXPENSE_REJECTED => 'Expense Rejected',
            self::EXPENSE_PRE_APPROVAL_REQUEST => 'Pre-Approval Request',
            self::EXPENSE_LIMIT_EXCEEDED => 'Expense Limit Exceeded',
            self::CUSTODY_REQUEST_CREATED => 'Custody Request Created',
            self::CUSTODY_REQUEST_APPROVED => 'Custody Request Approved',
            self::CUSTODY_REQUEST_REJECTED => 'Custody Request Rejected',
            self::CUSTODY_LOW_BALANCE => 'Low Custody Balance',
            self::CUSTODY_CASH_TRANSFER => 'Cash Transfer Confirmation',
            self::CUSTODY_RECONCILIATION_REMINDER => 'Custody Reconciliation Reminder',
            self::VAT_REPORTING_DEADLINE => 'VAT Reporting Deadline',
            self::TAX_DOCUMENT_REQUIRED => 'Tax Document Required',
            self::COMPLIANCE_VIOLATION => 'Compliance Violation',
            self::AUDIT_TRAIL_NOTIFICATION => 'Audit Trail Notification',
            self::ORDER_CREATED => 'Order Created',
            self::ORDER_STATUS_CHANGED => 'Order Status Changed',
            self::ORDER_VARIANCE_DETECTED => 'Order Variance Detected',
            self::GOODS_RECEIVED => 'Goods Received',
            self::RETURN_ORDER_SUBMITTED => 'Return Order Submitted',
        };
    }
}

