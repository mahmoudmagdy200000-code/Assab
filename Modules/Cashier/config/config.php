<?php

return [
    'name' => 'Cashier',

    // Activation settings
    'activation' => [
        'token_expiry_hours' => 24,
        'resend_limit_minutes' => 5,
    ],

    // Password settings
    'password' => [
        'min_length' => 8,
        'require_uppercase' => true,
        'require_number' => true,
        'require_special_char' => false,
    ],

    // OTP settings
    'otp' => [
        'length' => 6,
        'expiry_minutes' => 10,
        'max_attempts' => 3,
    ],

    // Profile image settings
    'profile_image' => [
        'max_size_kb' => 2048,
        'allowed_types' => ['jpg', 'jpeg', 'png'],
        'storage_path' => 'profiles/cashiers',
    ],
];
