<?php

use Illuminate\Support\Facades\Route;
use Modules\BrandOwner\Http\Controllers\BrandOwnerController;
use Modules\BrandOwner\Http\Controllers\CashSalesTransferController;

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
        Route::get('/{id}', [CashSalesTransferController::class, 'show'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('/{id}/approve', [CashSalesTransferController::class, 'approve'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('/{id}/reject', [CashSalesTransferController::class, 'reject'])
            ->where('id', '[0-9a-f-]{36}');
    });
