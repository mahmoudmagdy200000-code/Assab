<?php

return [
    'name' => 'BranchManagers',

    // Authentication settings
    'auth' => [
        'token_expiry_minutes' => 60 * 24 * 7, // 1 week
        'refresh_token_expiry_days' => 30,
    ],

    // Password settings
    'password' => [
        'min_length' => 8,
        'require_uppercase' => true,
        'require_number' => true,
        'require_special_char' => false,
        'expiry_days' => 90,
    ],

    // OTP settings
    'otp' => [
        'length' => 6,
        'expiry_minutes' => 10,
        'max_attempts' => 3,
        'resend_cooldown_minutes' => 2,
    ],

    // Profile image settings
    'profile_image' => [
        'max_size_kb' => 2048,
        'allowed_types' => ['jpg', 'jpeg', 'png'],
        'storage_path' => 'profiles/managers',
    ],

    // Session settings
    'session' => [
        'max_concurrent_sessions' => 3,
        'idle_timeout_minutes' => 30,
    ],

    // Dashboard settings
    'dashboard' => [
        'recent_activities_limit' => 10,
        'stats_cache_minutes' => 5,
    ],
];
