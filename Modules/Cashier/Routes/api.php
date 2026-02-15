<?php

use Illuminate\Support\Facades\Route;
use Modules\Cashier\Http\Controllers\{
    CashierController,
    CashierManagementController,
    CashierSettingsController,
    ProfileController
};
use Modules\Cashier\Http\Controllers\Auth\{
    LoginController,
    ActivationController,
    PasswordResetController
};

/*
|--------------------------------------------------------------------------
| API Routes - Public
|--------------------------------------------------------------------------
*/

Route::prefix('cashier/auth')->group(function () {
    Route::post('/login', [LoginController::class, 'login']);
    Route::post('/activate', [ActivationController::class, 'activate']);
    Route::post('/forgot-password', [PasswordResetController::class, 'sendOTP']);
    Route::post('/verify-otp', [PasswordResetController::class, 'verifyOTP']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
});

/*
|--------------------------------------------------------------------------
| API Routes - Branch Manager (Protected)
|--------------------------------------------------------------------------
*/

Route::prefix('branch-manager/cashiers')
    ->middleware(['auth:sanctum', 'branch.manager'])
    ->group(function () {
        // Get All Cashiers and Branch Managers (must be before {cashier} route to avoid route model binding conflict)
        Route::get('/all', [\Modules\Shift\Http\Controllers\CashierShiftController::class, 'getAllCashiersAndBranchManagerAccount'])
            ->name('branch-manager.cashiers.all');

        // Get available cashiers for shift assignment/reassignment (must be before {cashier} route)
        Route::get('/available-cashiers', [\Modules\Shift\Http\Controllers\CashierManagementController::class, 'getAvailableCashiers']);

        // Shift Management
        Route::get('/available-for-shift', [CashierManagementController::class, 'availableForShift']);
        Route::post('/assign-shifts', [CashierManagementController::class, 'assignShifts']);
        Route::put('/update-shifts', [CashierManagementController::class, 'updateShifts']);

        // Resend Activation
        Route::post('/resend-activation', [CashierManagementController::class, 'resendActivation']);

        // CRUD Operations
        Route::get('/', [CashierController::class, 'index']);
        Route::post('/', [CashierController::class, 'store']);
        Route::get('/{cashier}', [CashierController::class, 'show']);
        Route::put('/{cashier}', [CashierController::class, 'update']);
        Route::delete('/{cashier}', [CashierController::class, 'destroy']);

        // Status Management
        Route::post('/{cashier}/activate', [CashierController::class, 'activate']);
        Route::post('/{cashier}/deactivate', [CashierController::class, 'deactivate']);

        // Search & Filter
        Route::get('/search', [CashierManagementController::class, 'search']);
        Route::get('/statistics', [CashierManagementController::class, 'statistics']);
    });


/*
|--------------------------------------------------------------------------
| API Routes - Cashier (Protected)
|--------------------------------------------------------------------------
*/

Route::prefix('cashier')
    ->middleware(['auth:sanctum', 'cashier'])
    ->group(function () {
        // Authentication
        Route::post('/logout', [LoginController::class, 'logout']);

        // Profile Management (3.2.1.3 Profile Settings)
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/image', [ProfileController::class, 'uploadImage']);
        Route::get('/profile/statistics', [ProfileController::class, 'statistics']);

        // Settings (3.2.1.3 Account, Notifications, System)
        Route::get('/settings', [CashierSettingsController::class, 'index']);
        Route::get('/settings/account', [CashierSettingsController::class, 'account']);
        Route::put('/settings/notifications', [CashierSettingsController::class, 'updateNotifications']);
        Route::put('/settings/system', [CashierSettingsController::class, 'updateSystem']);
    });
