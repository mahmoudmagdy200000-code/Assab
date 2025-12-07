<?php

return [
    'name' => 'Purchase',
    
    /*
    |--------------------------------------------------------------------------
    | VAT Configuration
    |--------------------------------------------------------------------------
    |
    | The default VAT rate for Saudi Arabia is 15%.
    |
    */
    'vat_rate' => env('PURCHASE_VAT_RATE', 15.00),
    
    /*
    |--------------------------------------------------------------------------
    | Payment Terms
    |--------------------------------------------------------------------------
    |
    | Default payment terms in days.
    |
    */
    'default_payment_terms' => env('PURCHASE_PAYMENT_TERMS', 30),
    
    /*
    |--------------------------------------------------------------------------
    | Order Number Prefixes
    |--------------------------------------------------------------------------
    |
    | Prefixes for different order types.
    |
    */
    'order_prefixes' => [
        'direct_supplier' => 'DS',
        'via_purchasing_officer' => 'PO',
        'internal_transfer' => 'IT',
        'multiple_sources' => 'MS',
        'transfer_received' => 'TR',
        'goods_receipt' => 'GR',
        'invoice' => 'INV',
        'return' => 'RO',
        'compensatory' => 'CO',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Processing Times
    |--------------------------------------------------------------------------
    |
    | Processing time ranges for purchasing officer orders.
    |
    */
    'processing_times' => [
        'standard' => [
            'min_days' => 3,
            'max_days' => 5,
        ],
        'urgent' => [
            'min_days' => 1,
            'max_days' => 2,
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Ready Times
    |--------------------------------------------------------------------------
    |
    | Ready time options for internal transfers.
    |
    */
    'ready_times' => [
        '3_minutes' => 3,
        '1_hour' => 60,
        '2_hours' => 120,
        '3_hours' => 180,
        'more_than_3_hours' => 240,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | File Storage
    |--------------------------------------------------------------------------
    |
    | Configuration for file storage.
    |
    */
    'storage' => [
        'disk' => 'public',
        'path' => 'purchase',
        'max_file_size' => 10240, // 10MB in KB
        'allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Price Comparison
    |--------------------------------------------------------------------------
    |
    | Configuration for price comparison features.
    |
    */
    'price_comparison' => [
        'history_months' => 3,
        'cache_duration' => 60, // minutes
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Available notification channels.
    |
    */
    'notification_channels' => [
        'email',
        'whatsapp',
        'app',
        'sms',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Brand Owner
    |--------------------------------------------------------------------------
    |
    | Brand owner ID for escalation purposes.
    |
    */
    'brand_owner_id' => env('BRAND_OWNER_ID', null),
];
