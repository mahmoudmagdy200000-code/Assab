<?php

use Illuminate\Support\Facades\Route;
use Modules\PurchaseHistory\Http\Controllers\PurchaseHistoryController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('purchasehistories', PurchaseHistoryController::class)->names('purchasehistory');
});
