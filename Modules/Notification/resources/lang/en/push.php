<?php

/*
|--------------------------------------------------------------------------
| Notification copy (English)
|--------------------------------------------------------------------------
| One entry per NotificationType. Resolved against the RECIPIENT's locale, not
| the request locale — the sender is usually a queue worker or another user.
|
| `:placeholders` are filled from the notification's data array. Only use a
| placeholder the dispatching listener actually supplies: an unmatched one is
| rendered literally on the user's lock screen.
*/

return [

    // ── Shift management ────────────────────────────────────────────────────
    'shift_start_reminder' => [
        'title' => 'Shift starting soon',
        'body' => 'Your shift starts in 15 minutes.',
    ],
    'shift_start_overdue' => [
        'title' => 'Shift start overdue',
        'body' => 'Your shift should have started already. Open the app to start it.',
    ],
    'shift_end_reminder' => [
        'title' => 'Shift ending soon',
        'body' => 'Your shift ends in 30 minutes. Prepare the handover.',
    ],
    'shift_handover_pending' => [
        'title' => 'Handover waiting for you',
        'body' => 'A handover from :cashier_name is waiting for you to receive it.',
    ],
    'shift_handover_request' => [
        'title' => 'Handover request',
        'body' => 'A shift handover request needs your response.',
    ],
    'shift_handover_approval_required' => [
        'title' => 'Handover needs approval',
        'body' => 'A shift handover is waiting for your approval.',
    ],
    'shift_handover_approved' => [
        'title' => 'Handover approved',
        'body' => 'Your shift handover has been approved.',
    ],
    'shift_handover_rejected' => [
        'title' => 'Handover rejected',
        'body' => 'Your shift handover was rejected: :reason',
    ],
    'shift_handover_variance' => [
        'title' => 'Handover variance',
        'body' => 'A cash variance was detected during the handover.',
    ],
    'shift_handover_completed' => [
        'title' => 'Handover completed',
        'body' => 'The shift handover has been completed.',
    ],
    'shift_unapproved_handover' => [
        'title' => 'Unapproved handover',
        'body' => 'A handover has been waiting for approval too long and was escalated.',
    ],
    'shift_sales_rejected' => [
        'title' => 'Sales sheet returned',
        'body' => 'Your sales sheet was returned for review: :reason',
    ],

    // ── Sales and payment variance ──────────────────────────────────────────
    'cash_variance_high' => [
        'title' => 'Cash variance alert',
        'body' => 'A high cash variance was recorded on shift :shift_date.',
    ],
    'card_payment_discrepancy' => [
        'title' => 'Card payment discrepancy',
        'body' => 'Card settlement does not match the recorded sales.',
    ],
    'delivery_settlement_mismatch' => [
        'title' => 'Delivery settlement mismatch',
        'body' => 'An aggregator settlement does not match the recorded orders.',
    ],
    'high_variance_trend' => [
        'title' => 'Repeated variances',
        'body' => 'This branch has recorded high variances several times recently.',
    ],

    // ── Expenses ────────────────────────────────────────────────────────────
    'expense_submitted' => [
        'title' => 'Expense submitted',
        'body' => 'A new expense of :amount SAR is waiting for your approval.',
    ],
    'expense_approved' => [
        'title' => 'Expense approved',
        'body' => 'Your expense of :amount SAR has been approved.',
    ],
    'expense_rejected' => [
        'title' => 'Expense rejected',
        'body' => 'Your expense was rejected: :reason',
    ],
    'expense_pre_approval_request' => [
        'title' => 'Pre-approval requested',
        'body' => 'An expense pre-approval request needs your decision.',
    ],
    'expense_limit_exceeded' => [
        'title' => 'Expense limit exceeded',
        'body' => 'An expense exceeded the approved limit for this branch.',
    ],

    // ── Custody ─────────────────────────────────────────────────────────────
    'custody_request_created' => [
        'title' => 'Custody request created',
        'body' => 'A new custody request is waiting for review.',
    ],
    'custody_request_approved' => [
        'title' => 'Custody request approved',
        'body' => 'Your custody request has been approved.',
    ],
    'custody_request_rejected' => [
        'title' => 'Custody request rejected',
        'body' => 'Your custody request was rejected: :reason',
    ],
    'custody_low_balance' => [
        'title' => 'Low custody balance',
        'body' => 'Your custody balance has dropped below the safe threshold.',
    ],
    'custody_cash_transfer' => [
        'title' => 'Cash transfer',
        'body' => 'A cash transfer has been recorded on your custody.',
    ],
    'custody_reconciliation_reminder' => [
        'title' => 'Custody reconciliation due',
        'body' => 'Your custody reconciliation is due. Open the app to complete it.',
    ],

    // ── Financial compliance ────────────────────────────────────────────────
    'vat_reporting_deadline' => [
        'title' => 'VAT deadline approaching',
        'body' => 'The VAT reporting deadline is approaching.',
    ],
    'tax_document_required' => [
        'title' => 'Tax document required',
        'body' => 'A tax document is missing and must be uploaded.',
    ],
    'compliance_violation' => [
        'title' => 'Compliance violation',
        'body' => 'A compliance violation was detected and needs immediate attention.',
    ],
    'audit_trail_notification' => [
        'title' => 'Audit notice',
        'body' => 'An audit-relevant action was recorded on your account.',
    ],

    // ── Purchasing ──────────────────────────────────────────────────────────
    'order_created' => [
        'title' => 'New order',
        'body' => 'Order :order_number has been created.',
    ],
    'order_status_changed' => [
        'title' => 'Order updated',
        'body' => 'Order :order_number is now :status.',
    ],
    'order_variance_detected' => [
        'title' => 'Order variance',
        'body' => 'A variance was detected on order :order_number.',
    ],
    'goods_received' => [
        'title' => 'Goods received',
        'body' => 'A goods receipt has been recorded for order :order_number.',
    ],
    'return_order_submitted' => [
        'title' => 'Return submitted',
        'body' => 'A return order has been submitted and needs review.',
    ],
    'return_order_approved' => [
        'title' => 'Return approved',
        'body' => 'The return order has been approved.',
    ],

    // ── Account lifecycle ───────────────────────────────────────────────────
    'cashier_account_created' => [
        'title' => 'Welcome to Assab',
        'body' => 'Your cashier account is ready. Sign in to set your password.',
    ],
    'cashier_account_activated' => [
        'title' => 'Account activated',
        'body' => 'Your account has been activated. You can start your shifts.',
    ],
    'cashier_account_deactivated' => [
        'title' => 'Account deactivated',
        'body' => 'Your account has been deactivated. Contact your branch manager.',
    ],
    'branch_manager_account_created' => [
        'title' => 'Welcome to Assab',
        'body' => 'Your branch manager account is ready. Sign in to set your password.',
    ],
    'branch_manager_suspended' => [
        'title' => 'Account suspended',
        'body' => 'Your account has been suspended: :reason',
    ],

    // ── Dashboard approval chain ────────────────────────────────────────────
    'operation_final_approved' => [
        'title' => 'Operation approved',
        'body' => 'Operation :operation_number received final approval.',
    ],
    'operation_rejected' => [
        'title' => 'Operation rejected',
        'body' => 'Operation :operation_number was rejected: :reason',
    ],

    // ── Fixed assets ────────────────────────────────────────────────────────
    'asset_handover_started' => [
        'title' => 'Asset handover started',
        'body' => 'An asset handover session has started. Session :session_code.',
    ],
    'asset_handover_signature_required' => [
        'title' => 'Signature required',
        'body' => 'An asset handover is waiting for your signature.',
    ],
    'asset_handover_completed' => [
        'title' => 'Asset handover completed',
        'body' => 'The asset handover has been completed.',
    ],
    'asset_receive_requested' => [
        'title' => 'New asset awaiting receipt',
        'body' => 'Asset :asset_name (:public_id) was assigned to your branch — open receive requests and confirm receipt.',
    ],

    // ── Inventory ───────────────────────────────────────────────────────────
    'inventory_session_updated' => [
        'title' => 'Inventory updated',
        'body' => 'The monthly inventory session has been updated.',
    ],
];
