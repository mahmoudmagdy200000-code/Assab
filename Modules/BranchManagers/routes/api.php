<?php


use Modules\BranchManagers\Http\Controllers\BranchManagersController;


use Illuminate\Support\Facades\Route;
use Modules\BranchManagers\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('branch-manager')->group(function () {

    // Public routes (without authentication)
    Route::post('auth/first-login', [AuthController::class, 'firstLogin']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/reset-password-first-login', [AuthController::class, 'resetPasswordFirstLogin']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Add other protected routes here
        // Route::get('dashboard', [DashboardController::class, 'index']);
    });
});
