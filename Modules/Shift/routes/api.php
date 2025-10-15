<?php

use Illuminate\Support\Facades\Route;
use Modules\Shift\Http\Controllers\{
    PendingShiftController,
    InProgressShiftController,
    CompletedShiftController,
    ReassignmentShiftController,
    ShiftEndController,
    ShiftHandoverController,
    ShiftVarianceController
};

Route::prefix('api/branch-manager')->middleware(['auth:sanctum', 'role:branch_manager'])->group(function () {

    // Pending Shifts
    Route::prefix('shifts/pending')->group(function () {
        Route::get('/', [PendingShiftController::class, 'index']);
        Route::get('/{shift}', [PendingShiftController::class, 'show']);
        Route::post('/{shift}/reassign', [ReassignmentShiftController::class, 'reassign']);
    });

    // In-Progress Shifts
    Route::prefix('shifts/in-progress')->group(function () {
        Route::get('/', [InProgressShiftController::class, 'index']);
        Route::get('/{shift}', [InProgressShiftController::class, 'show']);
        Route::post('/{shift}/end', [ShiftEndController::class, 'endShiftOnly']);
        Route::post('/{shift}/end-with-handover', [ShiftEndController::class, 'endShiftWithHandover']);
    });

    // Completed Shifts
    Route::prefix('shifts/completed')->group(function () {
        Route::get('/', [CompletedShiftController::class, 'index']);
        Route::get('/{shift}', [CompletedShiftController::class, 'show']);
    });

    // Reassigned Shifts
    Route::prefix('shifts/reassigned')->group(function () {
        Route::get('/', [ReassignmentShiftController::class, 'index']);
        Route::get('/{shift}', [ReassignmentShiftController::class, 'show']);
    });

    // Handover Management
    Route::prefix('shifts/{shift}/handover')->group(function () {
        Route::post('/', [ShiftHandoverController::class, 'recordHandover']);
        Route::post('/approve', [ShiftHandoverController::class, 'approveHandover']);
        Route::post('/reject', [ShiftHandoverController::class, 'rejectHandover']);
    });

    // Variance Management
    Route::prefix('shifts/{shift}/variance')->group(function () {
        Route::post('/', [ShiftVarianceController::class, 'recordVariance']);
        Route::get('/', [ShiftVarianceController::class, 'getVarianceDetails']);
    });
});
