<?php

use Illuminate\Support\Facades\Route;
use Modules\BranchManagers\Http\Controllers\AuthController;
use Modules\Cashier\Http\Controllers\CashierController;
use Modules\BranchManagers\Http\Controllers\BranchManagersController;
use Modules\BranchManagers\Http\Controllers\DashboardController;
use Modules\BranchManagers\Http\Controllers\NotificationController;
use Modules\BranchManagers\Http\Controllers\ProfileController;
use Modules\BranchManagers\Http\Controllers\SettingsController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('branch-manager')->group(function () {

    // Public routes (no authentication)
    Route::post('auth/first-login', [AuthController::class, 'firstLogin']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        // Authentication
        Route::post('auth/reset-password-first-login', [AuthController::class, 'resetPasswordFirstLogin']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);





        // Dashboard
        Route::prefix('dashboard')->group(function () {
            Route::get('/', [DashboardController::class, 'index']);
            Route::get('/summary', [DashboardController::class, 'todaySummary']);
            Route::get('/stats', [DashboardController::class, 'quickStats']);
            Route::get('/activities', [DashboardController::class, 'recentActivities']);
        });

        // Profile Management
        Route::prefix('profile')->group(function () {
            Route::get('/', [ProfileController::class, 'show']);
            Route::put('/', [ProfileController::class, 'update']);
            Route::post('/image', [ProfileController::class, 'uploadImage']);
            Route::delete('/image', [ProfileController::class, 'deleteImage']);
            Route::post('/change-password', [ProfileController::class, 'changePassword']);
        });

        // Settings Management
        Route::prefix('settings')->group(function () {
            Route::get('/', [SettingsController::class, 'index']);

            // Notification Settings
            Route::get('/notifications', [SettingsController::class, 'getNotificationSettings']);
            Route::put('/notifications', [SettingsController::class, 'updateNotificationSettings']);

            // System Settings
            Route::get('/system', [SettingsController::class, 'getSystemSettings']);
            Route::put('/system', [SettingsController::class, 'updateSystemSettings']);

            // Branch Settings
            Route::get('/branch', [SettingsController::class, 'getBranchSettings']);
            Route::post('/aggregators', [SettingsController::class, 'updateAggregators']);
        });

        // Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/unread', [NotificationController::class, 'unread']);
            Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
            Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
            Route::delete('/{id}', [NotificationController::class, 'delete']);
            Route::delete('/clear-all', [NotificationController::class, 'clearAll']);
        });

        // ✅ Group for cashiers routes
        Route::prefix('cashiers')->controller(CashierController::class)->group(function () {
            Route::get('/', 'index'); // GET /branch-manager/cashiers
            Route::get('{id}', 'show'); // GET /branch-manager/cashiers/{id}
            Route::post('/', 'store'); // POST /branch-manager/cashiers
            Route::put('{id}', 'update'); // PUT /branch-manager/cashiers/{id}
            Route::delete('{id}', 'destroy'); // DELETE /branch-manager/cashiers/{id}
        });
    });
});
