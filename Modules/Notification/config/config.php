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

    /*
    |--------------------------------------------------------------------------
    | Default channels
    |--------------------------------------------------------------------------
    | Applied to recipients with no stored preference row for a notification
    | type — which is most users, since preferences are opt-in.
    */
    'defaults' => [
        'channels' => [
            'app',
            'push',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging
    |--------------------------------------------------------------------------
    | Device push. Distinct from the Pusher broadcast, which drives live in-app
    | UI for a client that is already open.
    |
    | driver:
    |   null     — no network calls, sends are logged and reported successful.
    |              The default, so the app runs without Firebase credentials.
    |   http_v1  — real delivery via the FCM HTTP v1 API. Requires a service
    |              account JSON key; the legacy server-key API was shut down in
    |              2024 and is not supported.
    */
    'fcm' => [
        // `?:` rather than an env() default: Laravel coerces the literal
        // `FCM_DRIVER=null` in .env to PHP null, which would otherwise read as
        // an unset driver everywhere downstream.
        'driver' => env('FCM_DRIVER') ?: 'null',

        // Absolute path to the Firebase service-account JSON key. Keep it
        // OUTSIDE version control — it grants send rights on the whole project.
        'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/service-account.json')),

        // Optional: overrides project_id from the key file.
        'project_id' => env('FIREBASE_PROJECT_ID'),

        'queue' => env('FCM_QUEUE', 'notifications'),
        'timeout' => (int) env('FCM_TIMEOUT', 10),

        // Android 8+ requires a notification channel id for anything to appear;
        // the mobile app must create a channel with this exact id.
        'android_channel_id' => env('FCM_ANDROID_CHANNEL_ID', 'assab_default'),

        // How long FCM holds a message for an offline device.
        'ttl_seconds' => (int) env('FCM_TTL_SECONDS', 86400),

        // Also push to the same human's account in the other world (dashboard
        // vs legacy mobile) via the ASAB identity map.
        'mirror_linked_identities' => (bool) env('FCM_MIRROR_LINKED_IDENTITIES', true),

        // Log suppressed sends under the null driver. Turn off in noisy envs.
        'log_null_driver' => (bool) env('FCM_LOG_NULL_DRIVER', true),
    ],

    'device_tokens' => [
        // Tokens untouched for this long are deleted by
        // `notification:prune-device-tokens`. FCM itself expires a registration
        // after ~270 days of app inactivity.
        'stale_after_days' => (int) env('FCM_STALE_TOKEN_DAYS', 180),
    ],
];
