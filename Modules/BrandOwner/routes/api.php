<?php

use Illuminate\Support\Facades\Route;
use Modules\BrandOwner\Http\Controllers\BrandOwnerController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('brandowners', BrandOwnerController::class)->names('brandowner');
});
