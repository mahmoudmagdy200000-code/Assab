<?php

use Illuminate\Support\Facades\Route;
use Modules\Notification\Http\Controllers\DeviceTokenController;
use Modules\Notification\Http\Controllers\NotificationController;
use Modules\Notification\Http\Controllers\NotificationPreferenceController;

Route::middleware(['auth:sanctum'])->group(function () {
    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
        Route::get('/{notification}', [NotificationController::class, 'show'])->name('notifications.show');
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
    });

    // Notification Preferences
    Route::prefix('notification-preferences')->group(function () {
        Route::get('/', [NotificationPreferenceController::class, 'index'])->name('notification-preferences.index');
        Route::post('/', [NotificationPreferenceController::class, 'store'])->name('notification-preferences.store');
        Route::put('/{preference}', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');
        Route::delete('/{preference}', [NotificationPreferenceController::class, 'destroy'])->name('notification-preferences.destroy');
    });

    /*
     | Device tokens (FCM).
     |
     | Every route is caller-scoped — no owner parameter exists, so a token can
     | only ever be registered against, or revoked from, the authenticated user.
     | Registration is throttled because it writes on every call and a client
     | bug (retry loop on token refresh) would otherwise hammer the table.
     */
    Route::prefix('device-tokens')->group(function () {
        Route::get('/', [DeviceTokenController::class, 'index'])->name('device-tokens.index');
        Route::post('/', [DeviceTokenController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('device-tokens.store');
        Route::delete('/', [DeviceTokenController::class, 'destroy'])->name('device-tokens.destroy');
        Route::delete('/all', [DeviceTokenController::class, 'destroyAll'])->name('device-tokens.destroy-all');
        Route::post('/test', [DeviceTokenController::class, 'test'])
            ->middleware('throttle:5,1')
            ->name('device-tokens.test');
    });
});
