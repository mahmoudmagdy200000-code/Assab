<?php

use Illuminate\Support\Facades\Route;
use Modules\Branch\Http\Controllers\BranchController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('branches', BranchController::class)->names('branch');
});
