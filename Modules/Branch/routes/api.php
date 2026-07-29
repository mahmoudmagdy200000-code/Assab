<?php

use Illuminate\Support\Facades\Route;
use Modules\Branch\Http\Controllers\BranchController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // Read-only picker: the controller implements index only, and branches are
    // created/edited from the ASAB dashboard, never from the mobile app.
    Route::get('branches', [BranchController::class, 'index'])->name('branch.index');
});
