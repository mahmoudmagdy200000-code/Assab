<?php

return [
    'name' => 'Purchase',

    // Order settings
    'order' => [
        'types' => [
            'direct_supplier' => 'Direct Supplier Order',
            'purchasing_officer' => 'Via Purchasing Officer',
            'internal_transfer' => 'Internal Transfer from Another Branch',
            'multiple_sources' => 'Multiple Orders from Different Sources',
        ],

        'statuses' => [
            'draft' => 'Draft',
            'pending' => 'Pending',
            'pending_confirmation' => 'Pending Your Confirmation',
            'pending_approval' => 'Pending Your Approval',
            'partial_confirmation' => 'Partial Confirmation',
            'confirmed' => 'Confirmed',
            'preparing' => 'Preparing',
            'on_the_way' => 'On The Way',
            'delivered' => 'Delivered',
            'delay_reported' => 'Delay Reported',
            'rejected' => 'Rejected',
            'canceled' => 'Canceled',
            'closed' => 'Closed',
        ],

        'priorities' => [
            'high' => 'High',
            'normal' => 'Normal',
            'low' => 'Low',
        ],
    ],

    // Receipt settings
    'receipt' => [
        'document_types' => [
            'invoice' => 'Invoice',
            'delivery_note' => 'Delivery Note',
            'receipt_without_document' => 'Receipt Without Document',
        ],

        'quality_levels' => [
            'normal' => 'Normal',
            'excellent' => 'Excellent',
            'poor' => 'Poor',
        ],

        'variance_types' => [
            'short' => 'Short',
            'damage' => 'Damage',
            'over' => 'Over',
        ],

        'variance_actions' => [
            'accept_variance' => 'Accept Variance as is',
            'create_compensatory_order' => 'Create Compensatory Order',
            'deduct_from_invoice' => 'Deduct from Invoice Value',
        ],
    ],

    // Return settings
    'return' => [
        'required_actions' => [
            'replacement' => 'Replacement with Good Product',
            'cash_refund' => 'Cash Refund',
            'credit_future' => 'Credit for Future Order',
        ],

        'statuses' => [
            'draft' => 'Draft',
            'pending' => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'closed' => 'Closed',
            'resolved' => 'Resolved',
        ],
    ],

    // VAT settings
    'vat' => [
        'rate' => 0.15, // 15%
        'enabled' => true,
    ],

    // Payment terms
    'payment_terms' => [
        'default_days' => 30,
        'default_term' => 'Net 30 days',
    ],

    // Notification methods
    'notification_methods' => [
        'email' => 'Email',
        'whatsapp' => 'WhatsApp',
        'app' => 'In-App',
        'sms' => 'SMS',
    ],

    // File upload settings
    'uploads' => [
        'max_size' => 10240, // KB (10MB)
        'allowed_extensions' => [
            'invoices' => ['pdf', 'jpg', 'jpeg', 'png'],
            'receipts' => ['jpg', 'jpeg', 'png'],
            'evidence' => ['pdf', 'jpg', 'jpeg', 'png'],
        ],
    ],

    // Price comparison settings
    'price_comparison' => [
        'history_months' => 3,
        'scoring_weights' => [
            'price' => 0.7,
            'rating' => 0.3,
        ],
    ],
];
