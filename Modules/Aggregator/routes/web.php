<?php

use Illuminate\Support\Facades\Route;
use Modules\Aggregator\Http\Controllers\AggregatorController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('aggregators', AggregatorController::class)->names('aggregator');
});
