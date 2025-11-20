<?php

use Illuminate\Support\Facades\Route;
use Modules\Shift\Http\Controllers\{
    PendingShiftController,
    InProgressShiftController,
    CompletedShiftController,
    ReassignmentShiftController,
    ShiftController,
    ShiftEndController,
    ShiftHandoverController,
    ShiftVarianceController,
    BranchManagerShiftController
};

/*
|--------------------------------------------------------------------------
| API Routes for Shift Module
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Branch Manager Shift Routes (My Shift)
|--------------------------------------------------------------------------
*/

Route::group([
    'prefix' => 'branch-manager/my-shift',
    'middleware' => ['auth:sanctum', 'branch.manager']
], function () {

    // Statistics (يجب أن يكون قبل {id} لتجنب الـ conflict)
    Route::get('statistics/summary', [BranchManagerShiftController::class, 'statistics'])
        ->name('branch-manager.shift.statistics');

    // Current Shift (Today's Shift)
    Route::get('current', [BranchManagerShiftController::class, 'current'])
        ->name('branch-manager.shift.current');

    // Shift History
    Route::get('history', [BranchManagerShiftController::class, 'index'])
        ->name('branch-manager.shift.history');

    // Start Shift
    Route::post('start', [BranchManagerShiftController::class, 'start'])
        ->name('branch-manager.shift.start');

    // End Shift Only (Without Handover)
    Route::post('end', [BranchManagerShiftController::class, 'endOnly'])
        ->name('branch-manager.shift.end');

    // End Shift With Handover
    Route::post('end-with-handover', [BranchManagerShiftController::class, 'endWithHandover'])
        ->name('branch-manager.shift.end-with-handover');

    // Record Handover (After Ending Shift)
    Route::post('record-handover', [BranchManagerShiftController::class, 'recordHandover'])
        ->name('branch-manager.shift.record-handover');

    // Shift Details (يجب أن يكون في النهاية)
    Route::get('{id}', [BranchManagerShiftController::class, 'show'])
        ->name('branch-manager.shift.show');
});

/*
|--------------------------------------------------------------------------
| Branch Manager - Shift Management Routes
|--------------------------------------------------------------------------
*/

Route::prefix('branch-manager')
    ->middleware(['auth:sanctum', 'branch.manager'])
    ->group(function () {

        Route::prefix('shifts')->group(function () {
            Route::get('/', [ShiftController::class, 'index'])->name('shifts.index');
        });

        Route::get('shifts/cashiers', [ShiftController::class, 'getAllCashiersShifts'])
            ->name('shifts.cashiers.index');

        /*
    |----------------------------------------------------------------------
    | Pending Shifts
    |----------------------------------------------------------------------
    */
        Route::prefix('shifts/pending')->group(function () {
            Route::get('/', [PendingShiftController::class, 'index'])->name('shifts.pending.index');
            Route::get('/{shift}', [PendingShiftController::class, 'show'])->name('shifts.pending.show');
            Route::get('/cashiers/{cashier}', [PendingShiftController::class, 'getPendingShiftByCashierId'])
                ->name('shifts.pending.cashier.show');
        });

        /*
    |----------------------------------------------------------------------
    | In-Progress Shifts
    |----------------------------------------------------------------------
    */
        Route::prefix('shifts/in-progress')->group(function () {
            Route::get('/', [InProgressShiftController::class, 'index'])->name('shifts.in-progress.index');
            Route::get('/{shift}', [InProgressShiftController::class, 'show'])->name('shifts.in-progress.show');
        });

        /*
    |----------------------------------------------------------------------
    | Completed Shifts
    |----------------------------------------------------------------------
    */
        Route::prefix('shifts/completed')->group(function () {
            Route::get('/', [CompletedShiftController::class, 'index'])->name('shifts.completed.index');
            Route::get('/{shift}', [CompletedShiftController::class, 'show'])->name('shifts.completed.show');
        });

        /*
    |----------------------------------------------------------------------
    | Reassigned Shifts
    |----------------------------------------------------------------------
    */
        Route::prefix('shifts/reassigned')->group(function () {
            Route::get('/', [ReassignmentShiftController::class, 'index'])->name('shifts.reassigned.index');
            Route::get('/{shift}', [ReassignmentShiftController::class, 'show'])->name('shifts.reassigned.show');
        });

        /*
    |----------------------------------------------------------------------
    | Shift Reassignment Actions
    |----------------------------------------------------------------------
    */
        Route::post('shifts/{shift}/reassign', [ReassignmentShiftController::class, 'reassign'])
            ->name('shifts.reassign');

        Route::post('shifts/{shift}/reassign-with-handover', [ReassignmentShiftController::class, 'reassignWithHandover']);

        Route::get('shifts/{shift}/available-cashiers', [ReassignmentShiftController::class, 'getAvailableCashiers'])
            ->name('shifts.available-cashiers');


        /*
    |----------------------------------------------------------------------
    | Shift End Actions
    |----------------------------------------------------------------------
    */
        Route::prefix('shifts/{shift}')->group(function () {
            // End shift only (without handover)
            Route::post('end', [ShiftEndController::class, 'endShiftOnly'])
                ->name('shifts.end');

            // End shift with handover
            Route::post('end-with-handover', [ShiftEndController::class, 'endShiftWithHandover'])
                ->name('shifts.end-with-handover');
        });

        // Helper: Calculate sales and VAT
        Route::post('shifts/calculate-sales', [ShiftEndController::class, 'calculateSales'])
            ->name('shifts.calculate-sales');

        /*
    |----------------------------------------------------------------------
    | Handover Management
    |----------------------------------------------------------------------
    */
        Route::prefix('shifts/{shift}/handover')->group(function () {
            // Record handover (after ending shift only)
            Route::post('/', [ShiftHandoverController::class, 'recordHandover'])
                ->name('shifts.handover.record');

            // Approve handover
            Route::post('approve', [ShiftHandoverController::class, 'approveHandover'])
                ->name('shifts.handover.approve');

            // Reject handover
            Route::post('reject', [ShiftHandoverController::class, 'rejectHandover'])
                ->name('shifts.handover.reject');

            // Get handover status
            Route::get('status', [ShiftHandoverController::class, 'getHandoverStatus'])
                ->name('shifts.handover.status');

            // Get available cashiers for handover
            Route::get('available-cashiers', [ShiftHandoverController::class, 'getAvailableCashiers'])
                ->name('shifts.handover.available-cashiers');
        });

        // Get Handover Summary
        Route::get('shifts/handover/summary', [ShiftHandoverController::class, 'getHandoverSummaries'])
            ->name('shifts.handover.summary');

        /*
    |----------------------------------------------------------------------
    | Variance Management
    |----------------------------------------------------------------------
    */
        Route::prefix('shifts/{shift}/variance')->group(function () {
            // Record variance
            Route::post('/', [ShiftVarianceController::class, 'recordVariance'])
                ->name('shifts.variance.record');

            // Get variance details
            Route::get('/', [ShiftVarianceController::class, 'getVarianceDetails'])
                ->name('shifts.variance.details');
        });

        /*
    |----------------------------------------------------------------------
    | Variance Alerts
    |----------------------------------------------------------------------
    */
        Route::prefix('shifts/variance-alerts')->group(function () {
            Route::get('/', [ShiftVarianceController::class, 'getVarianceAlerts'])
                ->name('shifts.variance-alerts.index');

            Route::post('{alert}/acknowledge', [ShiftVarianceController::class, 'acknowledgeAlert'])
                ->name('shifts.variance-alerts.acknowledge');
        });

        /*
    |----------------------------------------------------------------------
    | Variance Statistics
    |----------------------------------------------------------------------
    */
        Route::get('shifts/variance/statistics', [ShiftVarianceController::class, 'getVarianceStatistics'])
            ->name('shifts.variance.statistics');

        // filterCashierShifts
        Route::get('shifts/cashiers/filter', [ShiftController::class, 'filterCashierShifts'])
            ->name('shifts.cashiers.filter');

        // Show Cashier Shift
        Route::get('shifts/cashiers/{shift}', [ShiftController::class, 'getCashierShiftById'])
            ->name('shifts.cashiers.show');

        // show shift by id
        Route::get('shifts/{shift}', [ShiftController::class, 'show'])
            ->name('shifts.show');

        // get shift by cashier id
        Route::get('cashiers/{cashier}/shifts', [ShiftController::class, 'getShiftByCashierId'])
            ->name('cashiers.shifts.index');
    });

/*
|--------------------------------------------------------------------------
| Cashier - Shift Routes
|--------------------------------------------------------------------------
*/

Route::prefix('cashier')
    ->middleware(['auth:sanctum', 'cashier'])
    ->group(function () {

        // Cashier can view their own shifts
        Route::get('my-shifts/pending', [PendingShiftController::class, 'index'])
            ->name('cashier.shifts.pending');

        Route::get('my-shifts/in-progress', [InProgressShiftController::class, 'index'])
            ->name('cashier.shifts.in-progress');

        Route::get('my-shifts/completed', [CompletedShiftController::class, 'index'])
            ->name('cashier.shifts.completed');

        Route::get('my-shifts/{shift}', [PendingShiftController::class, 'show'])
            ->name('cashier.shifts.show');

        // Cashier can start shift
        Route::post('shifts/{shift}/start', function ($shift) {
            $shiftModel = \Modules\Shift\Models\CashierShift::findOrFail($shift);

            if ($shiftModel->cashier_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift is not assigned to you'
                ], 403);
            }

            if ($shiftModel->status->value !== 'not_started') {
                return response()->json([
                    'success' => false,
                    'message' => 'Shift has already been started'
                ], 400);
            }

            $shiftModel->startShift();

            // Load all relationships needed for the response
            $shiftModel->loadFullRelationships();

            return response()->json([
                'success' => true,
                'message' => 'Shift started successfully',
                'data' => new \Modules\Shift\Transformers\ShiftDetailResource($shiftModel)
            ]);
        })->name('cashier.shifts.start');

        // Cashier can end their own shift
        Route::post('shifts/{shift}/end', [ShiftEndController::class, 'endShiftOnly'])
            ->name('cashier.shifts.end');

        Route::post('shifts/{shift}/end-with-handover', [ShiftEndController::class, 'endShiftWithHandover'])
            ->name('cashier.shifts.end-with-handover');

        // Cashier can accept/reject handover
        Route::prefix('shifts/{shift}/handover')->group(function () {
            Route::post('accept', function ($shift) {
                $shiftModel = \Modules\Shift\Models\CashierShift::findOrFail($shift);

                if ($shiftModel->next_cashier_id !== auth()->id()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized: This handover is not for you'
                    ], 403);
                }

                app(\Modules\Shift\Services\HandoverService::class)
                    ->approveHandover($shiftModel, auth()->id(), 'cashier');

                return response()->json([
                    'success' => true,
                    'message' => 'Handover accepted successfully'
                ]);
            })->name('cashier.handover.accept');

            Route::post('reject', [ShiftHandoverController::class, 'rejectHandover'])
                ->name('cashier.handover.reject');
        });
    });
