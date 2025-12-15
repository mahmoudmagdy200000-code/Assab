<?php

use Illuminate\Support\Facades\Route;
use Modules\Custody\Http\Controllers\CustodyController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('custodies', CustodyController::class)->names('custody');
});
