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
| Branch Manager - Shift Management Routes
|--------------------------------------------------------------------------
*/

Route::prefix('branch-manager')
    ->middleware(['auth:sanctum', 'branch.manager'])
    ->group(function () {

        /*
        |----------------------------------------------------------------------
        | CRITICAL: Specific routes MUST come BEFORE wildcard routes
        |----------------------------------------------------------------------
        */

        // ✅ Specific routes first
        Route::get('shifts/cashiers', [ShiftController::class, 'getAllCashiersShifts'])
            ->name('shifts.cashiers.index');

        Route::get('shifts/cashiers/filter', [ShiftController::class, 'filterCashierShifts'])
            ->name('shifts.cashiers.filter');

        Route::get('shifts/cashiers/{shift}', [ShiftController::class, 'getCashierShiftById'])
            ->name('shifts.cashiers.show');

        // Pending Shifts - specific routes
        Route::prefix('shifts/pending')->group(function () {
            Route::get('/', [PendingShiftController::class, 'index'])->name('shifts.pending.index');
            Route::get('/{shift}', [PendingShiftController::class, 'show'])->name('shifts.pending.show');
            Route::get('/cashiers/{cashier}', [PendingShiftController::class, 'getPendingShiftByCashierId'])
                ->name('shifts.pending.cashier.show');
        });

        // In-Progress Shifts - specific routes
        Route::prefix('shifts/in-progress')->group(function () {
            Route::get('/', [InProgressShiftController::class, 'index'])->name('shifts.in-progress.index');
            Route::get('/{shift}', [InProgressShiftController::class, 'show'])->name('shifts.in-progress.show');
        });

        // Completed Shifts - specific routes
        Route::prefix('shifts/completed')->group(function () {
            Route::get('/', [CompletedShiftController::class, 'index'])->name('shifts.completed.index');
            Route::get('/{shift}', [CompletedShiftController::class, 'show'])->name('shifts.completed.show');
        });

        // Reassigned Shifts - specific routes
        Route::prefix('shifts/reassigned')->group(function () {
            Route::get('/', [ReassignmentShiftController::class, 'index'])->name('shifts.reassigned.index');
            Route::get('/{shift}', [ReassignmentShiftController::class, 'show'])->name('shifts.reassigned.show');
        });

        // Handover Summary - specific route
        Route::get('shifts/handover/summary', [ShiftHandoverController::class, 'getHandoverSummaries'])
            ->name('shifts.handover.summary');

        // Variance Statistics - specific route
        Route::get('shifts/variance/statistics', [ShiftVarianceController::class, 'getVarianceStatistics'])
            ->name('shifts.variance.statistics');

        // Variance Alerts - specific routes
        Route::prefix('shifts/variance-alerts')->group(function () {
            Route::get('/', [ShiftVarianceController::class, 'getVarianceAlerts'])
                ->name('shifts.variance-alerts.index');
            Route::post('{alert}/acknowledge', [ShiftVarianceController::class, 'acknowledgeAlert'])
                ->name('shifts.variance-alerts.acknowledge');
        });

        // ⚠️ Wildcard routes LAST
        Route::prefix('shifts')->group(function () {
            // General shifts index
            Route::get('/', [ShiftController::class, 'index'])->name('shifts.index');

            // Calculate sales helper
            Route::post('calculate-sales', [ShiftEndController::class, 'calculateSales'])
                ->name('shifts.calculate-sales');

            // Single shift operations - these use {shift} parameter
            Route::prefix('{shift}')->group(function () {
                // View shift details
                Route::get('/', [ShiftController::class, 'show'])->name('shifts.show');

                // Reassignment
                Route::post('reassign', [ReassignmentShiftController::class, 'reassign'])
                    ->name('shifts.reassign');
                Route::post('reassign-with-handover', [ReassignmentShiftController::class, 'reassignWithHandover']);
                Route::get('available-cashiers', [ReassignmentShiftController::class, 'getAvailableCashiers'])
                    ->name('shifts.available-cashiers');

                // End shift
                Route::post('end', [ShiftEndController::class, 'endShiftOnly'])
                    ->name('shifts.end');
                Route::post('end-with-handover', [ShiftEndController::class, 'endShiftWithHandover'])
                    ->name('shifts.end-with-handover');

                // Handover management
                Route::prefix('handover')->group(function () {
                    Route::post('/', [ShiftHandoverController::class, 'recordHandover'])
                        ->name('shifts.handover.record');
                    Route::post('approve', [ShiftHandoverController::class, 'approveHandover'])
                        ->name('shifts.handover.approve');
                    Route::post('reject', [ShiftHandoverController::class, 'rejectHandover'])
                        ->name('shifts.handover.reject');
                    Route::get('status', [ShiftHandoverController::class, 'getHandoverStatus'])
                        ->name('shifts.handover.status');
                    Route::get('available-cashiers', [ShiftHandoverController::class, 'getAvailableCashiers'])
                        ->name('shifts.handover.available-cashiers');
                });

                // Variance management
                Route::prefix('variance')->group(function () {
                    Route::post('/', [ShiftVarianceController::class, 'recordVariance'])
                        ->name('shifts.variance.record');
                    Route::get('/', [ShiftVarianceController::class, 'getVarianceDetails'])
                        ->name('shifts.variance.details');
                });
            });
        });

        // Get shifts by cashier ID
        Route::get('cashiers/{cashier}/shifts', [ShiftController::class, 'getShiftByCashierId'])
            ->name('cashiers.shifts.index');
    });

/*
|--------------------------------------------------------------------------
| Branch Manager Shift Routes (My Shift)
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Branch Manager Workday Management Routes (Section 3.1.3.1)
|--------------------------------------------------------------------------
*/

Route::prefix('branch-manager/workday')
    ->middleware(['auth:sanctum', 'branch.manager'])
    ->group(function () {

        // Section A: Shift Overview
        Route::get('/current', [BranchManagerShiftController::class, 'current']);
        Route::post('/start', [BranchManagerShiftController::class, 'startShift']);

        // Section B: Shift Details
        Route::get('/details', [BranchManagerShiftController::class, 'getShiftDetails']);

        // Section C: Handoffs Received
        Route::get('/handoffs', [BranchManagerShiftController::class, 'getHandoffsReceived']);
        Route::post('/handoffs/approve', [BranchManagerShiftController::class, 'approveHandoff']);
        Route::post('/handoffs/reject', [BranchManagerShiftController::class, 'rejectHandoff']);

        // Section D: Final Handover and End Shift
        Route::post('/end', [BranchManagerShiftController::class, 'endShift']);

        // Section E: Final Daily Close
        Route::get('/daily-close', [BranchManagerShiftController::class, 'getFinalDailyClose']);
        Route::post('/daily-close/submit', [BranchManagerShiftController::class, 'submitDailyReport']);
        Route::post('/daily-close/reopen', [BranchManagerShiftController::class, 'reopenShift']);

        // Shift History
        Route::get('/history', [BranchManagerShiftController::class, 'getShiftHistory']);


        // Start shift by manager
        Route::post('shifts/{shift}/start-by-manager', [ShiftController::class, 'startShiftByManager'])
            ->name('branch-manager.shifts.start');
    });

/*
|--------------------------------------------------------------------------
| Cashier - Shift Routes
|--------------------------------------------------------------------------
*/

Route::prefix('cashier')
    ->middleware(['auth:sanctum', 'cashier'])
    ->group(function () {
        Route::get('my-shifts/pending', [PendingShiftController::class, 'index'])
            ->name('cashier.shifts.pending');
        Route::get('my-shifts/in-progress', [InProgressShiftController::class, 'index'])
            ->name('cashier.shifts.in-progress');
        Route::get('my-shifts/completed', [CompletedShiftController::class, 'index'])
            ->name('cashier.shifts.completed');
        Route::get('my-shifts/{shift}', [PendingShiftController::class, 'show'])
            ->name('cashier.shifts.show');

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
            $shiftModel->loadFullRelationships();

            return response()->json([
                'success' => true,
                'message' => 'Shift started successfully',
                'data' => new \Modules\Shift\Transformers\ShiftDetailResource($shiftModel)
            ]);
        })->name('cashier.shifts.start');

        Route::post('shifts/{shift}/end', [ShiftEndController::class, 'endShiftOnly'])
            ->name('cashier.shifts.end');
        Route::post('shifts/{shift}/end-with-handover', [ShiftEndController::class, 'endShiftWithHandover'])
            ->name('cashier.shifts.end-with-handover');

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
