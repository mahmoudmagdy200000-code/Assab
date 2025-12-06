<?php

// use Illuminate\Support\Facades\Route;
// use Modules\Settings\Http\Controllers\SettingsController;

// /*
// |--------------------------------------------------------------------------
// | Branch Manager - Settings Routes
// |--------------------------------------------------------------------------
// */

// Route::prefix('branch-manager/settings')
//     ->middleware(['auth:sanctum', 'branch.manager'])
//     ->name('api.branch-manager.settings.') // Add name prefix
//     ->group(function () {

//         // Get all settings
//         Route::get('/', [SettingsController::class, 'index'])->name('index');

//         // Update profile
//         Route::put('/profile', [SettingsController::class, 'updateProfile'])->name('profile.update');

//         // Update system settings
//         Route::put('/system', [SettingsController::class, 'updateSystemSettings'])->name('system.update');

//         // Update notification settings
//         Route::put('/notifications', [SettingsController::class, 'updateNotificationSettings'])->name('notifications.update');

//         // Get account & branch details
//         Route::get('/account', [SettingsController::class, 'getAccountDetails'])->name('account');
//     });

// /*
// |--------------------------------------------------------------------------
// | Cashier - Settings Routes
// |--------------------------------------------------------------------------
// */

// Route::prefix('cashier/settings')
//     ->middleware(['auth:sanctum', 'cashier'])
//     ->name('api.cashier.settings.') // Add name prefix
//     ->group(function () {
//         Route::get('/', [SettingsController::class, 'index'])->name('index');
//         Route::put('/profile', [SettingsController::class, 'updateProfile'])->name('profile.update');
//         Route::put('/system', [SettingsController::class, 'updateSystemSettings'])->name('system.update');
//         Route::put('/notifications', [SettingsController::class, 'updateNotificationSettings'])->name('notifications.update');
//     });
