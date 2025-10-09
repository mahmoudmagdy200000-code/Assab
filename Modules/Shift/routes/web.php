<?php

use Illuminate\Support\Facades\Route;
use Modules\Shift\Http\Controllers\ShiftController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('shifts', ShiftController::class)->names('shift');
});
