#!/bin/bash

# Script لإنشاء الملفات المفقودة على الخادم
# استخدم: bash create_missing_files.sh

BASE_DIR="/home/u887387254/domains/ivory-snail-183262.hostingersite.com/public_html"

echo "=========================================="
echo "إنشاء الملفات المفقودة"
echo "=========================================="
echo ""

# إنشاء المجلدات إذا لم تكن موجودة
mkdir -p "$BASE_DIR/Modules/Cashier/routes"
mkdir -p "$BASE_DIR/Modules/BranchManagers/routes"

# 1. إنشاء Modules/Cashier/routes/api.php
cat > "$BASE_DIR/Modules/Cashier/routes/api.php" << 'CASHIER_API_EOF'
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
        // Get All Cashiers and Branch Managers (must be before {cashier} route to avoid route model binding conflict)
        Route::get('/all', [\Modules\Shift\Http\Controllers\CashierShiftController::class, 'getAllCashiersAndBranchManagerAccount'])
            ->name('cashiers.all');

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

        // Profile Management
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/image', [ProfileController::class, 'uploadImage']);
        Route::get('/profile/statistics', [ProfileController::class, 'statistics']);
    });
CASHIER_API_EOF

echo "✅ تم إنشاء Modules/Cashier/routes/api.php"

# 2. إنشاء Modules/Cashier/routes/web.php
cat > "$BASE_DIR/Modules/Cashier/routes/web.php" << 'CASHIER_WEB_EOF'
<?php

use Illuminate\Support\Facades\Route;
use Modules\Cashier\Http\Controllers\CashierController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('cashiers', CashierController::class)->names('cashier');
});
CASHIER_WEB_EOF

echo "✅ تم إنشاء Modules/Cashier/routes/web.php"

# 3. إنشاء Modules/BranchManagers/routes/api.php
cat > "$BASE_DIR/Modules/BranchManagers/routes/api.php" << 'BRANCHMANAGERS_API_EOF'
<?php

use Illuminate\Support\Facades\Route;
use Modules\BranchManagers\Http\Controllers\AuthController;
use Modules\BranchManagers\Http\Controllers\DashboardController;
use Modules\BranchManagers\Http\Controllers\NotificationController;
use Modules\BranchManagers\Http\Controllers\ProfileController;
use Modules\BranchManagers\Http\Controllers\SettingsController;

//fix

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

        // Settings Management
        // Route::prefix('settings')->group(function () {
        //     Route::get('/', [SettingsController::class, 'index']);

        //     // Notification Settings
        //     Route::get('/notifications', [SettingsController::class, 'getNotificationSettings']);
        //     Route::put('/notifications', [SettingsController::class, 'updateNotificationSettings']);

        //     // System Settings
        //     Route::get('/system', [SettingsController::class, 'getSystemSettings']);
        //     Route::put('/system', [SettingsController::class, 'updateSystemSettings']);

        //     // Branch Settings
        //     Route::get('/branch', [SettingsController::class, 'getBranchSettings']);
        //     Route::post('/aggregators', [SettingsController::class, 'updateAggregators']);
        // });

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
BRANCHMANAGERS_API_EOF

echo "✅ تم إنشاء Modules/BranchManagers/routes/api.php"

# 4. إنشاء Modules/BranchManagers/routes/web.php
cat > "$BASE_DIR/Modules/BranchManagers/routes/web.php" << 'BRANCHMANAGERS_WEB_EOF'
<?php

use Illuminate\Support\Facades\Route;
// use Modules\BranchManagers\Http\Controllers\BranchManagersController;

// Route::middleware(['auth', 'verified'])->group(function () {
//     Route::resource('branchmanagers', BranchManagersController::class)->names('branchmanagers');
// });
BRANCHMANAGERS_WEB_EOF

echo "✅ تم إنشاء Modules/BranchManagers/routes/web.php"

echo ""
echo "=========================================="
echo "✅ تم إنشاء جميع الملفات المفقودة!"
echo "=========================================="
echo ""
echo "يمكنك الآن تشغيل:"
echo "  composer install --no-dev"
echo ""
