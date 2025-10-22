<?php

use Illuminate\Support\Facades\Route;
use Modules\Settings\Http\Controllers\SettingsController;


Route::prefix('branch-manager/settings')
    ->middleware(['auth:sanctum', 'branch.manager'])
    ->group(function () {

    // Get all settings
    Route::get('/', [SettingsController::class, 'index'])->name('settings.index');

    // Update profile
    Route::put('/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile.update');

    // Update system settings
    Route::put('/system', [SettingsController::class, 'updateSystemSettings'])->name('settings.system.update');

    // Update notification settings
    Route::put('/notifications', [SettingsController::class, 'updateNotificationSettings'])->name('settings.notifications.update');

    // Get account & branch details
    Route::get('/account', [SettingsController::class, 'getAccountDetails'])->name('settings.account');
});

// Same for cashier
Route::prefix('cashier/settings')
    ->middleware(['auth:sanctum', 'cashier'])
    ->group(function () {
    Route::get('/', [SettingsController::class, 'index']);
    Route::put('/profile', [SettingsController::class, 'updateProfile']);
    Route::put('/system', [SettingsController::class, 'updateSystemSettings']);
    Route::put('/notifications', [SettingsController::class, 'updateNotificationSettings']);
});
