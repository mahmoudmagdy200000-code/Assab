<?php

use Illuminate\Support\Facades\Route;
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
});
