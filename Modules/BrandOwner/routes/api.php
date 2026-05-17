<?php

use Illuminate\Support\Facades\Route;
use Modules\BrandOwner\Http\Controllers\BrandOwnerController;
use Modules\BrandOwner\Http\Controllers\CashSalesTransferController;
use Modules\BrandOwner\Http\Controllers\OwnerPaymentFormController;
use Modules\BrandOwner\Http\Controllers\OwnerPaymentLogController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('brandowners', BrandOwnerController::class)->names('brandowner');
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
