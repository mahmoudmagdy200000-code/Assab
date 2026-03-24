<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\InventoryTaskController;
use Modules\Inventory\Http\Controllers\DailyQuickInventoryController;
use Modules\Inventory\Http\Controllers\DailyInventoryScheduleController;
use Modules\Inventory\Http\Controllers\MonthlyInventoryController;
use Modules\Inventory\Http\Controllers\WasteDamageReportController;

Route::middleware(['auth:sanctum', 'log.throttle'])->prefix('v1')->group(function () {
    Route::apiResource('inventories', InventoryController::class)->names('inventory');

    /*
    |--------------------------------------------------------------------------
    | My Tasks (assignments for cashier / branch manager)
    |--------------------------------------------------------------------------
    */
    Route::get('inventory/tasks', [InventoryTaskController::class, 'index'])->name('inventory.tasks.index');

    /*
    |--------------------------------------------------------------------------
    | Monthly Inventory Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('inventory/monthly')->group(function () {
        Route::get('/setup-info', [MonthlyInventoryController::class, 'setupInfo'])->name('inventory.monthly.setup-info');
        Route::get('/staff-options', [MonthlyInventoryController::class, 'staffOptions'])->name('inventory.monthly.staff-options');
        Route::get('/last-team', [MonthlyInventoryController::class, 'lastTeam'])->name('inventory.monthly.last-team');
        Route::post('/', [MonthlyInventoryController::class, 'store'])->middleware('throttle:inventory-write')->name('inventory.monthly.store');
        Route::get('/', [MonthlyInventoryController::class, 'index'])->name('inventory.monthly.index');
        Route::get('/status-counts', [MonthlyInventoryController::class, 'statusCounts'])->name('inventory.monthly.status-counts');
        Route::get('/comparison', [MonthlyInventoryController::class, 'comparison'])->name('inventory.monthly.comparison');
        Route::get('/{id}', [MonthlyInventoryController::class, 'show'])->name('inventory.monthly.show');
        Route::get('/{id}/products', [MonthlyInventoryController::class, 'products'])->name('inventory.monthly.products');
        Route::put('/{id}/products/{productId}', [MonthlyInventoryController::class, 'updateProduct'])->middleware('throttle:inventory-write')->name('inventory.monthly.products.update');
        Route::post('/{id}/products/{productId}/claim', [MonthlyInventoryController::class, 'claimProduct'])->middleware('throttle:inventory-write')->name('inventory.monthly.products.claim');
        Route::post('/{id}/products/{productId}/release', [MonthlyInventoryController::class, 'releaseProduct'])->middleware('throttle:inventory-write')->name('inventory.monthly.products.release');
        Route::get('/{id}/progress', [MonthlyInventoryController::class, 'progress'])->name('inventory.monthly.progress');
        Route::post('/{id}/save-progress', [MonthlyInventoryController::class, 'saveProgress'])->name('inventory.monthly.save-progress');
        Route::post('/{id}/review', [MonthlyInventoryController::class, 'review'])->name('inventory.monthly.review');
        Route::post('/{id}/submit', [MonthlyInventoryController::class, 'submit'])->name('inventory.monthly.submit');
        Route::post('/{id}/approve', [MonthlyInventoryController::class, 'approve'])->name('inventory.monthly.approve');
        Route::post('/{id}/return-to-draft', [MonthlyInventoryController::class, 'returnToDraft'])->name('inventory.monthly.return-to-draft');
        Route::get('/{id}/report', [MonthlyInventoryController::class, 'report'])->name('inventory.monthly.report');
        Route::post('/{id}/export', [MonthlyInventoryController::class, 'export'])->name('inventory.monthly.export');
        Route::get('/{id}/timelines', [MonthlyInventoryController::class, 'timelines'])->name('inventory.monthly.timelines');
        Route::get('/{id}/feedback', [MonthlyInventoryController::class, 'getFeedback'])->name('inventory.monthly.feedback.index');
        Route::post('/{id}/feedback', [MonthlyInventoryController::class, 'addFeedback'])->name('inventory.monthly.feedback.store');
    });

    /*
    |--------------------------------------------------------------------------
    | Daily Quick Inventory Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('inventory/daily-quick')->group(function () {
        // Dashboard
        Route::get('/dashboard', [DailyQuickInventoryController::class, 'dashboard'])->name('inventory.daily-quick.dashboard');

        // Helper endpoints (branch items = source for daily inventory; closed-items = legacy)
        Route::get('/branch-items', [DailyQuickInventoryController::class, 'getBranchItems'])->name('inventory.daily-quick.branch-items');
        Route::get('/closed-items', [DailyQuickInventoryController::class, 'getClosedOrderItems'])->name('inventory.daily-quick.closed-items');
        Route::get('/employees', [DailyQuickInventoryController::class, 'getEmployees'])->name('inventory.daily-quick.employees');

        // Daily inventory schedule (Account Manager): configure items, start date/time per branch
        Route::prefix('schedules')->group(function () {
            Route::get('/branch/{branchId}', [DailyInventoryScheduleController::class, 'show'])->name('inventory.daily-quick.schedules.show');
            Route::post('/', [DailyInventoryScheduleController::class, 'store'])->name('inventory.daily-quick.schedules.store');
            Route::put('/{id}', [DailyInventoryScheduleController::class, 'update'])->name('inventory.daily-quick.schedules.update');
            Route::delete('/branch/{branchId}', [DailyInventoryScheduleController::class, 'destroy'])->name('inventory.daily-quick.schedules.destroy');
        });

        // Session management
        Route::post('/sessions', [DailyQuickInventoryController::class, 'createSession'])->name('inventory.daily-quick.sessions.create');
        Route::get('/sessions', [DailyQuickInventoryController::class, 'getSessions'])->name('inventory.daily-quick.sessions.index');
        Route::get('/sessions/{id}', [DailyQuickInventoryController::class, 'getSession'])->name('inventory.daily-quick.sessions.show');
        Route::put('/sessions/{id}', [DailyQuickInventoryController::class, 'updateSession'])->name('inventory.daily-quick.sessions.update');
        Route::delete('/sessions/{id}', [DailyQuickInventoryController::class, 'deleteSession'])->name('inventory.daily-quick.sessions.delete');
        Route::post('/sessions/{id}/submit', [DailyQuickInventoryController::class, 'submitSession'])->name('inventory.daily-quick.sessions.submit');
        Route::post('/sessions/{id}/approve', [DailyQuickInventoryController::class, 'approveSession'])->name('inventory.daily-quick.sessions.approve');
        Route::post('/sessions/{id}/reject', [DailyQuickInventoryController::class, 'rejectSession'])->name('inventory.daily-quick.sessions.reject');
        Route::post('/sessions/{id}/resubmit', [DailyQuickInventoryController::class, 'resubmitSession'])->name('inventory.daily-quick.sessions.resubmit');
        Route::get('/sessions/{id}/discrepancy-report', [DailyQuickInventoryController::class, 'getDiscrepancyReport'])->name('inventory.daily-quick.sessions.discrepancy-report');
        Route::post('/sessions/{id}/discrepancy-reviewed', [DailyQuickInventoryController::class, 'markDiscrepancyReviewed'])->name('inventory.daily-quick.sessions.discrepancy-reviewed');
        Route::get('/sessions/{id}/timelines', [DailyQuickInventoryController::class, 'getTimelines'])->name('inventory.daily-quick.sessions.timelines');
        Route::get('/sessions/{id}/summary', [DailyQuickInventoryController::class, 'getSessionSummary'])->name('inventory.daily-quick.sessions.summary');
        Route::post('/sessions/{id}/start', [DailyQuickInventoryController::class, 'startSession'])->name('inventory.daily-quick.sessions.start');
        Route::get('/products/{itemId}/last-quantities', [DailyQuickInventoryController::class, 'getLastQuantities'])->name('inventory.daily-quick.products.last-quantities');

        // Item management
        Route::post('/sessions/{id}/items', [DailyQuickInventoryController::class, 'addItem'])->name('inventory.daily-quick.sessions.items.create');
        Route::put('/sessions/{sessionId}/items/{itemId}', [DailyQuickInventoryController::class, 'updateItem'])->name('inventory.daily-quick.sessions.items.update');
        Route::delete('/sessions/{sessionId}/items/{itemId}', [DailyQuickInventoryController::class, 'removeItem'])->name('inventory.daily-quick.sessions.items.delete');
    });

    /*
    |--------------------------------------------------------------------------
    | Waste & Damage Inventory Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('inventory/waste-damage')->group(function () {
        Route::get('/assignment-info', [WasteDamageReportController::class, 'assignmentInfo'])->name('inventory.waste-damage.assignment-info');
        Route::get('/products-from-closed-orders', [WasteDamageReportController::class, 'productsFromClosedOrders'])->name('inventory.waste-damage.products');
        Route::get('/employees', [WasteDamageReportController::class, 'employees'])->name('inventory.waste-damage.employees');
        Route::post('/reports', [WasteDamageReportController::class, 'store'])->name('inventory.waste-damage.reports.store');
        Route::get('/reports', [WasteDamageReportController::class, 'index'])->name('inventory.waste-damage.reports.index');
        Route::get('/reports/{id}', [WasteDamageReportController::class, 'show'])->name('inventory.waste-damage.reports.show');
        Route::post('/reports/{id}/items', [WasteDamageReportController::class, 'storeItem'])->name('inventory.waste-damage.reports.items.store');
        Route::put('/reports/{id}/items/{itemId}', [WasteDamageReportController::class, 'updateItem'])->name('inventory.waste-damage.reports.items.update');
        Route::delete('/reports/{id}/items/{itemId}', [WasteDamageReportController::class, 'deleteItem'])->name('inventory.waste-damage.reports.items.delete');
        Route::post('/reports/{id}/submit', [WasteDamageReportController::class, 'submit'])->name('inventory.waste-damage.reports.submit');
    });
});
