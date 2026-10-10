<?php

use Illuminate\Support\Facades\Route;
use Modules\Shift\Http\Controllers\BranchManagerShiftController;
use Modules\Shift\Http\Controllers\CashierManagementController;
use Modules\Shift\Http\Controllers\CashierShiftController;
use Modules\Shift\Http\Controllers\CompletedShiftController;
use Modules\Shift\Http\Controllers\InProgressShiftController;
use Modules\Shift\Http\Controllers\PendingShiftController;
use Modules\Shift\Http\Controllers\ReassignmentShiftController;
use Modules\Shift\Http\Controllers\ShiftController;
use Modules\Shift\Http\Controllers\ShiftEndController;
use Modules\Shift\Http\Controllers\ShiftHandoverController;
use Modules\Shift\Http\Controllers\ShiftRequestsController;
use Modules\Shift\Http\Controllers\ShiftVarianceController;

// Explicit physical presentation/return facts; existing legacy request APIs retain NULL attempt identity.
Route::middleware('auth:sanctum')->group(function () {
    $controller = \Modules\Shift\Http\Controllers\ShiftTransferAttemptController::class;
    Route::post('shift-transfers/{type}/{id}/present', [$controller, 'present'])->whereIn('type', ['handover', 'manager_transfer']);
    Route::post('shift-transfer-attempts/{attempt}/returns', [$controller, 'initiate']);
    Route::post('shift-transfer-attempts/{attempt}/reject', [$controller, 'reject']);
    Route::post('shift-transfer-attempts/{attempt}/confirm-receipt', [$controller, 'receipt']);
    Route::post('shift-transfer-returns/{return}/confirm', [$controller, 'confirm']);
    Route::post('shift-reports/{shift}/recount', [$controller, 'recount']);
});

/*
|--------------------------------------------------------------------------
| Branch Manager - Shift Management Routes
|--------------------------------------------------------------------------
*/

Route::prefix('branch-manager')
    ->middleware(['auth:sanctum', 'branch.manager.or.cashier', 'log.throttle'])
    ->group(function () {

        /*
        |----------------------------------------------------------------------
        | Section 3.1.2.1: Cashiers Management Routes
        |----------------------------------------------------------------------
        */
        Route::prefix('cashiers')->group(function () {
            // Section 3.1.2.1.1: Cashiers Listing
            Route::get('/', [CashierManagementController::class, 'index'])
                ->name('cashiers.index');

            // All active cashiers in branch (no pagination) - accessible by cashier token too
            Route::get('/all', [CashierManagementController::class, 'all'])
                ->name('cashiers.all');

            // Section 3.1.2.1.1.1: Manage Cashiers (Create)
            Route::post('/', [CashierManagementController::class, 'store'])
                ->name('cashiers.store');

            // Check if cashier email is already registered (GET with ?email= or POST with body)
            Route::get('/check-email', [CashierManagementController::class, 'checkEmail'])
                ->name('cashiers.check-email.get');
            Route::post('/check-email', [CashierManagementController::class, 'checkEmail'])
                ->name('cashiers.check-email');

            // Section 3.1.2.1.1.2: Search Cashiers
            Route::get('/search', [CashierManagementController::class, 'search'])
                ->name('cashiers.search');

            // Section 3.1.2.1.1.3: Filter Cashiers
            Route::get('/filter', [CashierManagementController::class, 'filter'])
                ->name('cashiers.filter');

            // Get available cashiers for shift assignment/reassignment
            Route::get('/available-cashiers', [CashierManagementController::class, 'getAvailableCashiers'])
                ->name('cashiers.available-cashiers');

            // Section 3.1.2.1.1.4: View Detailed Cashier Information (UUID only - avoids matching "check-email", etc.)
            Route::get('/{cashier}', [CashierManagementController::class, 'show'])
                ->where('cashier', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('cashiers.show');

            // Update Cashier
            Route::put('/{cashier}', [CashierManagementController::class, 'update'])
                ->where('cashier', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('cashiers.update');

            // Get Cashier's Shifts
            Route::get('/{cashier}/shifts', [ShiftController::class, 'getShiftByCashierId'])
                ->where('cashier', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('cashiers.shifts.index');
        });

        /*
        |----------------------------------------------------------------------
        | Section 3.1.2.1.1.4: Cashier Shifts by Status
        |----------------------------------------------------------------------
        */

        // All Cashiers Shifts
        Route::get('shifts/cashiers', [ShiftController::class, 'getAllCashiersShifts'])
            ->name('shifts.cashiers.index');
        Route::get('shifts/cashiers/filter', [ShiftController::class, 'filterCashierShifts'])
            ->name('shifts.cashiers.filter');
        Route::get('shifts/cashiers/{shift}', [ShiftController::class, 'getCashierShiftById'])
            ->name('shifts.cashiers.show');

        // B.1: Pending Shifts
        Route::prefix('shifts/pending')->group(function () {
            Route::get('/', [PendingShiftController::class, 'index'])->name('shifts.pending.index');
            Route::get('/{shift}', [PendingShiftController::class, 'show'])->name('shifts.pending.show');
            Route::get('/cashiers/{cashier}', [PendingShiftController::class, 'getPendingShiftByCashierId'])
                ->name('shifts.pending.cashier.show');
        });

        // B.2: In-Progress Shifts
        Route::prefix('shifts/in-progress')->group(function () {
            Route::get('/', [InProgressShiftController::class, 'index'])->name('shifts.in-progress.index');
            Route::get('/{shift}', [InProgressShiftController::class, 'show'])->name('shifts.in-progress.show');
        });

        // B.3: Completed Shifts
        Route::prefix('shifts/completed')->group(function () {
            Route::get('/', [CompletedShiftController::class, 'index'])->name('shifts.completed.index');
            Route::get('/{shift}', [CompletedShiftController::class, 'show'])->name('shifts.completed.show');
        });

        // B.4: Reassigned Shifts
        Route::prefix('shifts/reassigned')->group(function () {
            Route::get('/', [ReassignmentShiftController::class, 'index'])->name('shifts.reassigned.index');
            Route::get('/{shift}', [ReassignmentShiftController::class, 'show'])->name('shifts.reassigned.show');
        });

        // Requests: handovers & variances (both cashier and branch manager, data by auth token)
        Route::prefix('requests')->group(function () {
            Route::get('handovers', [ShiftRequestsController::class, 'handovers'])
                ->name('requests.handovers');
            Route::get('variances', [ShiftRequestsController::class, 'variances'])
                ->name('requests.variances');
        });

        // Handover Summary
        Route::get('shifts/handover/summary', [ShiftHandoverController::class, 'getHandoverSummaries'])
            ->name('shifts.handover.summary');

        // Variance Statistics
        Route::get('shifts/variance/statistics', [ShiftVarianceController::class, 'getVarianceStatistics'])
            ->name('shifts.variance.statistics');

        // Variance Alerts
        Route::prefix('shifts/variance-alerts')->group(function () {
            Route::get('/', [ShiftVarianceController::class, 'getVarianceAlerts'])
                ->name('shifts.variance-alerts.index');
            Route::post('{alert}/acknowledge', [ShiftVarianceController::class, 'acknowledgeAlert'])
                ->name('shifts.variance-alerts.acknowledge');
        });

        /*
        |----------------------------------------------------------------------
        | Shift Operations (with {shift} parameter)
        |----------------------------------------------------------------------
        */
        Route::prefix('shifts')->group(function () {
            // General shifts index
            Route::get('/', [ShiftController::class, 'index'])->name('shifts.index');

            // Calculate sales helper
            Route::post('calculate-sales', [ShiftEndController::class, 'calculateSales'])
                ->name('shifts.calculate-sales');

            // Start shift by manager (for cashier) - must be before {shift} route
            Route::post('start-by-manager/{shiftId}', [CashierShiftController::class, 'startShiftByManager'])
                ->name('shifts.start-by-manager');

            // Single shift operations
            Route::prefix('{shift}')->group(function () {
                // View shift details
                Route::get('/', [ShiftController::class, 'getCashierShiftById'])->name('shifts.getCashierShiftById');

                // Reassignment
                Route::post('reassign', [ReassignmentShiftController::class, 'reassign'])
                    ->name('shifts.reassign');
                Route::post('reassign-with-handover', [ReassignmentShiftController::class, 'reassignWithHandover'])
                    ->name('shifts.reassign-with-handover')
                    ->middleware('asab.idempotency:optional,transaction,legacy');
                Route::get('available-cashiers', [ReassignmentShiftController::class, 'getAvailableCashiers'])
                    ->name('shifts.available-cashiers');

                // End shift (Options 1-4)
                Route::post('end', [ShiftEndController::class, 'endShiftOnly'])
                    ->name('shifts.end')
                    ->middleware('asab.idempotency:optional,transaction,legacy');
                Route::post('cash-reconciliation/preview', [ShiftEndController::class, 'previewCashReconciliation'])
                    ->name('shifts.cash-reconciliation.preview')
                    ->middleware('throttle:60,1');
                Route::post('end-with-handover', [ShiftEndController::class, 'endShiftWithHandover'])
                    ->name('shifts.end-with-handover')
                    ->middleware('asab.idempotency:optional,transaction,legacy');
                Route::post('start-handover', [ShiftEndController::class, 'startHandover'])
                    ->name('shifts.start-handover')
                    ->middleware('asab.idempotency:optional,transaction,legacy');
                Route::get('available-recipients', [ShiftEndController::class, 'getAvailableCashiersForHandover'])
                    ->name('shifts.available-recipients');

                // Handover management
                Route::prefix('handover')->group(function () {
                    Route::post('/', [ShiftHandoverController::class, 'recordHandover'])
                        ->name('shifts.handover.record')
                        ->middleware('asab.idempotency:optional,transaction,legacy');
                    Route::post('approve', [ShiftHandoverController::class, 'approveHandover'])
                        ->name('shifts.handover.approve')
                        ->middleware('asab.idempotency:optional,transaction,legacy');
                    Route::post('reject', [ShiftHandoverController::class, 'rejectHandover'])
                        ->name('shifts.handover.reject');
                    Route::post('replace-recipient', [ShiftHandoverController::class, 'replaceRecipient'])
                        ->name('shifts.handover.replace-recipient')
                        ->middleware('asab.idempotency:required,transaction,legacy');
                    Route::get('status', [ShiftHandoverController::class, 'getHandoverStatus'])
                        ->name('shifts.handover.status');
                    Route::get('available-cashiers', [ShiftHandoverController::class, 'getAvailableCashiers'])
                        ->name('shifts.handover.available-cashiers');
                    // Rejection Details and Decision
                    Route::get('rejection-details', [ShiftHandoverController::class, 'getRejectionDetails'])
                        ->name('shifts.handover.rejection.details');
                    Route::post('rejection-decision', [ShiftHandoverController::class, 'processRejectionDecision'])
                        ->name('shifts.handover.rejection.decision');
                });

                // Variance management
                Route::prefix('variance')->group(function () {
                    Route::post('/', [ShiftVarianceController::class, 'recordVariance'])
                        ->name('shifts.variance.record');
                    Route::get('/', [ShiftVarianceController::class, 'getVarianceDetails'])
                        ->name('shifts.variance.details');
                });

                // Responsibility management (variance approval context)
                Route::prefix('responsibility')->group(function () {
                    Route::get('details', [ShiftHandoverController::class, 'getResponsibilityDetails'])
                        ->name('shifts.responsibility.details');
                    Route::post('approve', [ShiftVarianceController::class, 'approveResponsibility'])
                        ->name('shifts.responsibility.approve');
                    Route::post('reject', [ShiftVarianceController::class, 'rejectResponsibility'])
                        ->name('shifts.responsibility.reject');
                });
            });
        });

        Route::post('cash-transfers/{transfer}/replace-recipient', [BranchManagerShiftController::class, 'replaceTransferRecipient'])
            ->name('branch-manager.cash-transfers.replace-recipient')
            ->middleware('asab.idempotency:required,transaction,legacy');
    });

/*
|--------------------------------------------------------------------------
| Section 3.1.3: Branch Manager Workday Management Routes
|--------------------------------------------------------------------------
*/
Route::prefix('branch-manager/workday')
    ->middleware(['auth:sanctum', 'branch.manager', 'log.throttle'])
    ->group(function () {

        // Section A: Shift Overview
        Route::get('/current', [BranchManagerShiftController::class, 'current'])
            ->name('workday.current');
        Route::post('/start', [BranchManagerShiftController::class, 'start'])
            ->name('workday.start');

        // Section B: Shift Details
        Route::get('/details', [BranchManagerShiftController::class, 'getShiftDetails'])
            ->name('workday.details');

        // Section C: Handoffs Received
        Route::get('/handoffs', [BranchManagerShiftController::class, 'getHandoffsReceived'])
            ->name('workday.handoffs');
        Route::get('/handoffs/cashier/{handoverId}', [BranchManagerShiftController::class, 'getCashierHandoverDetails'])
            ->name('workday.handoffs.cashier.details');
        Route::post('/handoffs/approve', [BranchManagerShiftController::class, 'approveHandoff'])
            ->name('workday.handoffs.approve')
            ->middleware('asab.idempotency:optional,transaction,legacy');
        Route::post('/handoffs/reject', [BranchManagerShiftController::class, 'rejectHandoff'])
            ->name('workday.handoffs.reject');

        // Section C: Rejection Details and Decision
        Route::get('/handoffs/rejection/{shift}', [BranchManagerShiftController::class, 'getRejectionDetails'])
            ->name('workday.handoffs.rejection.details');
        Route::post('/handoffs/rejection/{shift}/decision', [BranchManagerShiftController::class, 'processRejectionDecision'])
            ->name('workday.handoffs.rejection.decision');

        // Section D: Final Handover and End Shift
        Route::post('/end', [BranchManagerShiftController::class, 'endShift'])
            ->name('workday.end');
        Route::get('/final-handover/{shiftId}', [BranchManagerShiftController::class, 'getManagerFinalHandover'])
            ->name('workday.final-handover.details');

        // Section E: Final Daily Close
        Route::get('/daily-close', [BranchManagerShiftController::class, 'getFinalDailyClose'])
            ->name('workday.daily-close');
        Route::put('/daily-close', [BranchManagerShiftController::class, 'updateFinalDailyClose'])
            ->name('workday.daily-close.update');
        Route::post('/daily-close/submit', [BranchManagerShiftController::class, 'submitDailyReport'])
            ->name('workday.daily-close.submit');
        Route::post('/daily-close/reopen', [BranchManagerShiftController::class, 'reopenShift'])
            ->name('workday.daily-close.reopen');

        // Shift History
        Route::get('/history', [BranchManagerShiftController::class, 'getShiftHistory'])
            ->name('workday.history');
    });

/*
|--------------------------------------------------------------------------
| Section 3.2.2: Cashier - Shift Routes
|--------------------------------------------------------------------------
*/
Route::prefix('cashier')
    ->middleware(['auth:sanctum', 'cashier', 'log.throttle'])
    ->group(function () {

        /*
        |----------------------------------------------------------------------
        | Section 3.2.2.1.1: Shifts Overview
        |----------------------------------------------------------------------
        */
        Route::get('my-shifts', [CashierShiftController::class, 'index'])
            ->name('cashier.shifts.index');

        // View shifts by status
        Route::get('my-shifts/pending', [CashierShiftController::class, 'pendingShifts'])
            ->name('cashier.shifts.pending');
        Route::get('my-shifts/in-progress', [CashierShiftController::class, 'inProgressShifts'])
            ->name('cashier.shifts.in-progress');
        Route::get('my-shifts/completed', [CashierShiftController::class, 'completedShifts'])
            ->name('cashier.shifts.completed');
        Route::get('my-shifts/reassigned', [CashierShiftController::class, 'reassignedShifts'])
            ->name('cashier.shifts.reassigned');

        // Requests: handovers, variances, reassigned shifts (data by auth token)
        Route::prefix('requests')->group(function () {
            Route::get('handovers', [ShiftRequestsController::class, 'handovers'])
                ->name('cashier.requests.handovers');
            Route::get('variances', [ShiftRequestsController::class, 'variances'])
                ->name('cashier.requests.variances');
            Route::get('reassigned-shifts', [ShiftRequestsController::class, 'reassignedShifts'])
                ->name('cashier.requests.reassigned-shifts');
        });

        // Responsibility management - must be before my-shifts/{shift}
        Route::prefix('my-shifts/{shift}/responsibility')->group(function () {
            Route::get('details', [ShiftHandoverController::class, 'getResponsibilityDetails'])
                ->name('cashier.responsibility.details');
            Route::post('approve', [ShiftVarianceController::class, 'cashierApproveResponsibility'])
                ->name('cashier.responsibility.approve');
            Route::post('reject', [ShiftVarianceController::class, 'cashierRejectResponsibility'])
                ->name('cashier.responsibility.reject');
        });

        // View shift details
        Route::get('my-shifts/{shift}', [CashierShiftController::class, 'show'])
            ->name('cashier.shifts.show');

        /*
        |----------------------------------------------------------------------
        | Section 3.2.2.1.1.1: Start Shift
        |----------------------------------------------------------------------
        */
        Route::post('shifts/{shift}/start', [CashierShiftController::class, 'startShift'])
            ->name('cashier.shifts.start');

        /*
        |----------------------------------------------------------------------
        | Reassigned Shift: Accept / Reject (when manager reassigns to this cashier)
        |----------------------------------------------------------------------
        */
        Route::post('shifts/{shift}/reassign/accept', [CashierShiftController::class, 'acceptReassignedShift'])
            ->name('cashier.shifts.reassign.accept');
        Route::post('shifts/{shift}/reassign/reject', [CashierShiftController::class, 'rejectReassignedShift'])
            ->name('cashier.shifts.reassign.reject');

        /*
        |----------------------------------------------------------------------
        | Section 3.2.2.1.1.1: End Shift (Options 1-4)
        |----------------------------------------------------------------------
        */
        Route::post('shifts/{shift}/end', [ShiftEndController::class, 'endShiftOnly'])
            ->name('cashier.shifts.end')
            ->middleware('asab.idempotency:optional,transaction,legacy');
        Route::post('shifts/{shift}/cash-reconciliation/preview', [ShiftEndController::class, 'previewCashReconciliation'])
            ->name('cashier.shifts.cash-reconciliation.preview')
            ->middleware('throttle:60,1');
        Route::post('shifts/{shift}/end-with-handover', [ShiftEndController::class, 'endShiftWithHandover'])
            ->name('cashier.shifts.end-with-handover')
            ->middleware('asab.idempotency:optional,transaction,legacy');
        Route::post('shifts/{shift}/start-handover', [ShiftEndController::class, 'startHandover'])
            ->name('cashier.shifts.start-handover')
            ->middleware('asab.idempotency:optional,transaction,legacy');
        Route::get('shifts/{shift}/available-recipients', [ShiftEndController::class, 'getAvailableCashiersForHandover'])
            ->name('cashier.shifts.available-recipients');

        // Calculate sales helper
        Route::post('shifts/calculate-sales', [ShiftEndController::class, 'calculateSales'])
            ->name('cashier.shifts.calculate-sales');

        /*
        |----------------------------------------------------------------------
        | Section 3.2.2.1.1.3: Handover Management
        |----------------------------------------------------------------------
        */
        Route::prefix('shifts/{shift}/handover')->group(function () {
            // Record handover
            Route::post('/', [ShiftHandoverController::class, 'recordHandover'])
                ->name('cashier.handover.record')
                ->middleware('asab.idempotency:optional,transaction,legacy');

            // Receive Handover - Accept (as next cashier)
            Route::post('accept', [ShiftHandoverController::class, 'acceptHandover'])
                ->name('cashier.handover.accept');

            // Receive Handover - Reject (as next cashier)
            Route::post('reject', [ShiftHandoverController::class, 'rejectHandover'])
                ->name('cashier.handover.reject');

            // Replace recipient
            Route::post('replace-recipient', [ShiftHandoverController::class, 'replaceRecipient'])
                ->name('cashier.handover.replace-recipient')
                ->middleware('asab.idempotency:required,transaction,legacy');

            // Edit handover after manager rejection
            Route::post('edit', [ShiftHandoverController::class, 'editHandoverAfterRejection'])
                ->name('cashier.handover.edit');

            // Get handover status
            Route::get('status', [ShiftHandoverController::class, 'getHandoverStatus'])
                ->name('cashier.handover.status');

            // Get available cashiers for handover
            Route::get('available-cashiers', [ShiftHandoverController::class, 'getAvailableCashiers'])
                ->name('cashier.handover.available-cashiers');
        });

        /*
        |----------------------------------------------------------------------
        | Section 3.2.2.1.2: Shift History
        |----------------------------------------------------------------------
        */
        Route::get('shifts/history', [CashierShiftController::class, 'shiftHistory'])
            ->name('cashier.shifts.history');

        // Weekly summary
        Route::get('shifts/weekly-summary', [CashierShiftController::class, 'weeklySummary'])
            ->name('cashier.shifts.weekly-summary');
    });

/*
|--------------------------------------------------------------------------
| Shared Routes (Both Manager and Cashier)
|--------------------------------------------------------------------------
*/
Route::prefix('shifts')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        // Get shift details (with appropriate authorization)
        Route::get('{shift}/details', [ShiftController::class, 'getShiftDetails'])
            ->name('shifts.details');

        // Get shift progress
        Route::get('{shift}/progress', [ShiftController::class, 'getShiftProgress'])
            ->name('shifts.progress');
    });
