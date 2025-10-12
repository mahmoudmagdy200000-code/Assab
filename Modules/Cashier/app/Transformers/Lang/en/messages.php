<?php

return [
    'cashier' => [
        'created' => 'Cashier created successfully',
        'updated' => 'Cashier updated successfully',
        'deleted' => 'Cashier deleted successfully',
        'activated' => 'Cashier activated successfully',
        'deactivated' => 'Cashier deactivated successfully',
        'not_found' => 'Cashier not found',
        'already_active' => 'Cashier is already active',
        'already_deactivated' => 'Cashier is already deactivated',
        'has_active_shifts' => 'Cannot perform this action. Cashier has active shifts.',
        'activation_sent' => 'Activation link sent successfully',
        'activation_resent' => 'Activation link resent successfully',
    ],
    'auth' => [
        'login_success' => 'Login successful',
        'login_failed' => 'Invalid credentials',
        'logout_success' => 'Logged out successfully',
        'account_inactive' => 'Your account is not active',
        'account_pending' => 'Your account is pending activation',
        'account_deactivated' => 'Your account has been deactivated',
    ],
    'activation' => [
        'success' => 'Account activated successfully',
        'failed' => 'Activation failed',
        'token_invalid' => 'Invalid activation token',
        'token_expired' => 'Activation token has expired',
        'already_activated' => 'Account is already activated',
    ],
    'password' => [
        'reset_sent' => 'Password reset OTP sent successfully',
        'reset_success' => 'Password reset successfully',
        'otp_invalid' => 'Invalid or expired OTP',
        'otp_verified' => 'OTP verified successfully',
        'current_incorrect' => 'Current password is incorrect',
    ],
    'profile' => [
        'updated' => 'Profile updated successfully',
        'image_uploaded' => 'Profile image uploaded successfully',
    ],
    'validation' => [
        'email_exists' => 'This email is already registered',
        'phone_exists' => 'This phone number is already registered',
        'invalid_phone' => 'Please provide a valid Saudi phone number',
    ],
];
