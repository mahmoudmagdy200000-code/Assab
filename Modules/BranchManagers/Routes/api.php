<?php

use Illuminate\Support\Facades\Route;
use Modules\BranchManagers\Http\Controllers\AuthController;
use Modules\BranchManagers\Http\Controllers\BranchManagerNotificationSettingsController;
use Modules\BranchManagers\Http\Controllers\BranchManagerPriceComparisonController;
use Modules\BranchManagers\Http\Controllers\BranchManagerSettingsAggregatorController;
use Modules\BranchManagers\Http\Controllers\BranchManagerSettingsController;
use Modules\BranchManagers\Http\Controllers\BrandManagerInventoryController;
use Modules\BranchManagers\Http\Controllers\DashboardController;
use Modules\BranchManagers\Http\Controllers\NotificationController;
use Modules\BranchManagers\Http\Controllers\ProfileController;

// fix

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
    // «activate Account» — the handler demands a proof (first-login token in the
    // header or body, or the default password), so it does not need auth:sanctum
    // and no longer dead-ends a build that omits the Bearer header.
    Route::post('auth/reset-password-first-login', [AuthController::class, 'resetPasswordFirstLogin'])
        ->middleware('throttle:6,1');

    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        // Authentication
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Branch Management
        Route::prefix('branches')->group(function () {
            Route::get('/', [\Modules\Branch\Http\Controllers\BranchController::class, 'index']);
        });

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

        // Inventory work queues (BrandManagerInventoryManagementScreen)
        Route::prefix('inventory')->group(function () {
            Route::get('daily-requests', [BrandManagerInventoryController::class, 'dailyIndex']);
            Route::get('daily-requests/{requestId}', [BrandManagerInventoryController::class, 'dailyShow']);
            Route::post('daily-requests/{requestId}/approve', [BrandManagerInventoryController::class, 'dailyApprove']);
            Route::post('daily-requests/{requestId}/reject', [BrandManagerInventoryController::class, 'dailyReject']);

            Route::get('waste-damage-requests', [BrandManagerInventoryController::class, 'wasteDamageIndex']);
            Route::get('waste-damage-requests/{requestId}', [BrandManagerInventoryController::class, 'wasteDamageShow']);
            Route::post('waste-damage-requests/{requestId}/approve', [BrandManagerInventoryController::class, 'wasteDamageApprove']);
            Route::post('waste-damage-requests/{requestId}/reject', [BrandManagerInventoryController::class, 'wasteDamageReject']);
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
    });
});

/*
|--------------------------------------------------------------------------
| Branch Manager - Settings Screen
|--------------------------------------------------------------------------
| Account details and password reset are available to any authenticated
| user (read/update of their own account). Aggregator and notification
| management is restricted to branch managers.
*/

Route::prefix('branch-manager/settings')->group(function () {

    // Available to any authenticated user (by token).
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('account-details', [BranchManagerSettingsController::class, 'accountDetails']);
        Route::post('reset-password', [BranchManagerSettingsController::class, 'resetPassword']);
    });

    // Branch manager only.
    Route::middleware(['auth:sanctum', 'branch.manager'])->group(function () {

        // Settings snapshot + aggregators
        Route::get('aggregators', [BranchManagerSettingsController::class, 'snapshot']);
        Route::get('aggregators/available', [BranchManagerSettingsAggregatorController::class, 'available']);
        Route::get('aggregators/assigned', [BranchManagerSettingsAggregatorController::class, 'assigned']);
        Route::post('aggregators', [BranchManagerSettingsAggregatorController::class, 'store']);
        Route::delete('aggregators/{aggregatorId}', [BranchManagerSettingsAggregatorController::class, 'destroy']);
        Route::patch('aggregators/{aggregatorId}/status', [BranchManagerSettingsAggregatorController::class, 'updateStatus']);

        // Notification toggles
        Route::prefix('notifications')->group(function () {
            Route::patch('shift-variance-alerts', [BranchManagerNotificationSettingsController::class, 'shiftVarianceAlerts']);
            Route::patch('daily-inventory-reminders', [BranchManagerNotificationSettingsController::class, 'dailyInventoryReminders']);
            Route::patch('approved-aggregators-only', [BranchManagerNotificationSettingsController::class, 'approvedAggregatorsOnly']);
            Route::patch('asset-transfer-requests', [BranchManagerNotificationSettingsController::class, 'assetTransferRequests']);
            Route::patch('allow-split-shift-handovers', [BranchManagerNotificationSettingsController::class, 'allowSplitShiftHandovers']);
        });
    });
});

/*
|--------------------------------------------------------------------------
| Branch Manager - Price Comparison Screen
|--------------------------------------------------------------------------
| Saved price-comparison snapshots, full details, file export, and creating a
| purchase order from a recommended source. Comparisons are recorded by the
| Purchase module; every endpoint is scoped to the manager's own branch.
*/

Route::prefix('branch-manager/price-comparisons')
    ->middleware(['auth:sanctum', 'branch.manager'])
    ->name('api.branch-manager.price-comparisons.')
    ->group(function () {
        Route::get('/', [BranchManagerPriceComparisonController::class, 'index'])
            ->name('index');

        Route::get('/{comparisonId}', [BranchManagerPriceComparisonController::class, 'show'])
            ->where('comparisonId', '[0-9a-fA-F-]{36}')
            ->name('show');

        Route::get('/{comparisonId}/export', [BranchManagerPriceComparisonController::class, 'export'])
            ->where('comparisonId', '[0-9a-fA-F-]{36}')
            ->name('export');

        Route::post('/{comparisonId}/orders', [BranchManagerPriceComparisonController::class, 'createOrder'])
            ->where('comparisonId', '[0-9a-fA-F-]{36}')
            ->name('orders.store');
    });
