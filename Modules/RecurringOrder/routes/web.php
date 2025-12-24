<?php

use Illuminate\Support\Facades\Route;
use Modules\RecurringOrder\Http\Controllers\RecurringOrderController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('recurringorders', RecurringOrderController::class)->names('recurringorder');
});
