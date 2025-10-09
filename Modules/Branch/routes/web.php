<?php

use Illuminate\Support\Facades\Route;
use Modules\Branch\Http\Controllers\BranchController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('branches', BranchController::class)->names('branch');
});
