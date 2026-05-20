<?php

return [
    'sms' => [
        'provider' => env('SMS_PROVIDER', 'stc'), // stc, mobily, zain
        'stc' => [
            'api_url' => env('STC_SMS_API_URL', ''),
            'api_key' => env('STC_SMS_API_KEY', ''),
            'sender_id' => env('STC_SMS_SENDER_ID', ''),
        ],
        'mobily' => [
            'api_url' => env('MOBILY_SMS_API_URL', ''),
            'api_key' => env('MOBILY_SMS_API_KEY', ''),
            'sender_id' => env('MOBILY_SMS_SENDER_ID', ''),
        ],
        'zain' => [
            'api_url' => env('ZAIN_SMS_API_URL', ''),
            'api_key' => env('ZAIN_SMS_API_KEY', ''),
            'sender_id' => env('ZAIN_SMS_SENDER_ID', ''),
        ],
        'max_per_day' => env('SMS_MAX_PER_DAY', 10),
    ],

    'email' => [
        'from_address' => env('NOTIFICATION_EMAIL_FROM', 'noreply@assab.com'),
        'from_name' => env('NOTIFICATION_EMAIL_FROM_NAME', 'Assab'),
    ],

    'in_app' => [
        'expiration_days' => env('NOTIFICATION_EXPIRATION_DAYS', 7),
        'max_title_length' => 60,
        'max_message_length' => 120,
    ],

    'delivery' => [
        'timeout_seconds' => env('NOTIFICATION_DELIVERY_TIMEOUT', 2),
    ],
];
