<?php

use Illuminate\Support\Facades\Route;
use Modules\Cashier\Http\Controllers\CashierController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('cashiers', CashierController::class)->names('cashier');
});
