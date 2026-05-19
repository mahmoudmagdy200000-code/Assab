<?php

use Illuminate\Support\Facades\Route;
use Modules\BrandOwner\Http\Controllers\AuthController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerInventoryController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerReturnController;
use Modules\BrandOwner\Http\Controllers\CashSalesTransferController;
use Modules\BrandOwner\Http\Controllers\OwnerPaymentFormController;
use Modules\BrandOwner\Http\Controllers\OwnerPaymentLogController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('brandowners', BrandOwnerController::class)->names('brandowner');
});

/*
|--------------------------------------------------------------------------
| Brand Owner - Authentication (mirrors Branch Manager auth)
|--------------------------------------------------------------------------
*/

Route::prefix('brand-owner')->group(function () {
    // Public
    Route::post('auth/first-login', [AuthController::class, 'firstLogin']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

    // Protected
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/reset-password-first-login', [AuthController::class, 'resetPasswordFirstLogin']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Brand Owner - Cash Sales Transfer
|--------------------------------------------------------------------------
| Brand-owner specific endpoints for the cash sales transfer details screen
| plus approve/reject actions.
*/

Route::prefix('brand-owner/cash-sales-transfers')
    ->middleware(['auth:sanctum', 'brand.owner', 'log.throttle'])
    ->name('api.brand-owner.cash-sales-transfers.')
    ->group(function () {
        Route::get('/', [CashSalesTransferController::class, 'index']);
        Route::get('/{id}', [CashSalesTransferController::class, 'show'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('/{id}/approve', [CashSalesTransferController::class, 'approve'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('/{id}/reject', [CashSalesTransferController::class, 'reject'])
            ->where('id', '[0-9a-f-]{36}');
    });

/*
|--------------------------------------------------------------------------
| Brand Owner - Payment Form (submit) + Payment Logs
|--------------------------------------------------------------------------
*/

Route::prefix('brand-owner')
    ->middleware(['auth:sanctum', 'brand.owner', 'log.throttle'])
    ->name('api.brand-owner.')
    ->group(function () {
        Route::post('payment-form', [OwnerPaymentFormController::class, 'store']);

        Route::get('payment-logs', [OwnerPaymentLogController::class, 'index']);
        Route::get('payment-logs/{paymentLogId}', [OwnerPaymentLogController::class, 'show'])
            ->where('paymentLogId', '[0-9a-f-]{36}');
    });

/*
|--------------------------------------------------------------------------
| Brand Owner - Inventory (BrandManagerInventoryManagementScreen)
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Brand Owner - Purchase Return Escalations
|--------------------------------------------------------------------------
| Approve / reject an escalated purchase return. Listing + details for
| escalated returns are served by the shared /purchase/returns endpoints
| (controller branches on user type).
*/

Route::prefix('brand-owner/purchase/returns')
    ->middleware(['auth:sanctum', 'brand.owner', 'log.throttle'])
    ->name('api.brand-owner.purchase.returns.')
    ->group(function () {
        Route::post('/{returnId}/approve-escalation', [BrandOwnerReturnController::class, 'approveEscalation'])
            ->where('returnId', '[0-9a-f-]{36}')
            ->name('approve-escalation');
        Route::post('/{returnId}/reject-escalation', [BrandOwnerReturnController::class, 'rejectEscalation'])
            ->where('returnId', '[0-9a-f-]{36}')
            ->name('reject-escalation');
    });

Route::prefix('brand-owner/inventory')
    ->middleware(['auth:sanctum', 'brand.owner'])
    ->name('api.brand-owner.inventory.')
    ->group(function () {
        Route::get('daily-requests', [BrandOwnerInventoryController::class, 'dailyIndex']);
        Route::get('daily-requests/{requestId}', [BrandOwnerInventoryController::class, 'dailyShow'])
            ->where('requestId', '[0-9a-f-]{36}');
        Route::post('daily-requests/{requestId}/approve', [BrandOwnerInventoryController::class, 'dailyApprove'])
            ->where('requestId', '[0-9a-f-]{36}');
        Route::post('daily-requests/{requestId}/reject', [BrandOwnerInventoryController::class, 'dailyReject'])
            ->where('requestId', '[0-9a-f-]{36}');

        Route::get('waste-damage-requests', [BrandOwnerInventoryController::class, 'wasteDamageIndex']);
        Route::get('waste-damage-requests/{requestId}', [BrandOwnerInventoryController::class, 'wasteDamageShow'])
            ->where('requestId', '[0-9a-f-]{36}');
        Route::post('waste-damage-requests/{requestId}/approve', [BrandOwnerInventoryController::class, 'wasteDamageApprove'])
            ->where('requestId', '[0-9a-f-]{36}');
        Route::post('waste-damage-requests/{requestId}/reject', [BrandOwnerInventoryController::class, 'wasteDamageReject'])
            ->where('requestId', '[0-9a-f-]{36}');
    });
