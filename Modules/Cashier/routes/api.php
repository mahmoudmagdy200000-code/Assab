<?php

use Illuminate\Support\Facades\Route;
use Modules\Cashier\Http\Controllers\CashierController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('cashiers', CashierController::class)->names('cashier');
});
