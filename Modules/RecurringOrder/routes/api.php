<?php

use Illuminate\Support\Facades\Route;
use Modules\RecurringOrder\Http\Controllers\RecurringOrderController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('recurringorders', RecurringOrderController::class)->names('recurringorder');
});
