<?php

return [
    'name' => 'Shift',

    // Variance Thresholds
    'variance' => [
        'alert_threshold_amount' => env('SHIFT_VARIANCE_THRESHOLD_AMOUNT', 100), // SAR
        'alert_threshold_percentage' => env('SHIFT_VARIANCE_THRESHOLD_PERCENTAGE', 5), // %
    ],

    // Shift Timing
    'timing' => [
        'pending_shifts_min_days' => 7,
        'pending_shifts_max_days' => 30,
        'auto_end_shift_after_hours' => 12,
    ],

    // File Upload
    'uploads' => [
        'pos_receipts' => [
            'path' => 'receipts',
            'max_size' => 5120, // KB
            'allowed_types' => ['jpg', 'jpeg', 'png', 'pdf'],
        ],
        'variance_files' => [
            'path' => 'variance/supporting-files',
            'max_size' => 5120, // KB
            'allowed_types' => ['jpg', 'jpeg', 'png', 'pdf'],
            'max_files' => 5,
        ],
        'rejection_files' => [
            'path' => 'handover/rejections',
            'max_size' => 5120, // KB
            'allowed_types' => ['jpg', 'jpeg', 'png', 'pdf'],
            'max_files' => 5,
        ],
    ],

    // VAT Configuration
    'vat_percentage' => env('VAT_PERCENTAGE', 15),
];
