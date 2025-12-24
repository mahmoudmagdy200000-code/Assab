<?php

use Illuminate\Support\Facades\Route;
use Modules\Supplier\Http\Controllers\SupplierController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('suppliers', SupplierController::class)->names('supplier');
});
