<?php

use Illuminate\Support\Facades\Route;
use Modules\PurchaseHistory\Http\Controllers\PurchaseHistoryController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('purchasehistories', PurchaseHistoryController::class)->names('purchasehistory');
});
