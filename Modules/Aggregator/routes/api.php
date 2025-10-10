<?php

use Illuminate\Support\Facades\Route;
use Modules\Aggregator\Http\Controllers\AggregatorController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('aggregators', AggregatorController::class)->names('aggregator');
});
