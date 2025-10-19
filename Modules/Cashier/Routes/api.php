<?php

use Illuminate\Support\Facades\Route;
use Modules\Cashier\Http\Controllers\{
    CashierController,
    CashierManagementController,
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

        // Profile Management
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/image', [ProfileController::class, 'uploadImage']);
        Route::get('/profile/statistics', [ProfileController::class, 'statistics']);
    });
