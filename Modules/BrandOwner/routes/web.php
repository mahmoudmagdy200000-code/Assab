<?php

use Illuminate\Support\Facades\Route;
use Modules\BrandOwner\Http\Controllers\BrandOwnerController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('brandowners', BrandOwnerController::class)->names('brandowner');
});
