<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\DailyQuickInventoryController;
use Modules\Inventory\Http\Controllers\MonthlyInventoryController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('inventories', InventoryController::class)->names('inventory');

    /*
    |--------------------------------------------------------------------------
    | Monthly Inventory Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('inventory/monthly')->group(function () {
        Route::get('/setup-info', [MonthlyInventoryController::class, 'setupInfo'])->name('inventory.monthly.setup-info');
        Route::get('/staff-options', [MonthlyInventoryController::class, 'staffOptions'])->name('inventory.monthly.staff-options');
        Route::get('/last-team', [MonthlyInventoryController::class, 'lastTeam'])->name('inventory.monthly.last-team');
        Route::post('/', [MonthlyInventoryController::class, 'store'])->name('inventory.monthly.store');
        Route::get('/', [MonthlyInventoryController::class, 'index'])->name('inventory.monthly.index');
        Route::get('/comparison', [MonthlyInventoryController::class, 'comparison'])->name('inventory.monthly.comparison');
        Route::get('/{id}', [MonthlyInventoryController::class, 'show'])->name('inventory.monthly.show');
        Route::get('/{id}/products', [MonthlyInventoryController::class, 'products'])->name('inventory.monthly.products');
        Route::put('/{id}/products/{productId}', [MonthlyInventoryController::class, 'updateProduct'])->name('inventory.monthly.products.update');
        Route::post('/{id}/products/{productId}/claim', [MonthlyInventoryController::class, 'claimProduct'])->name('inventory.monthly.products.claim');
        Route::post('/{id}/products/{productId}/release', [MonthlyInventoryController::class, 'releaseProduct'])->name('inventory.monthly.products.release');
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
        // Helper endpoints
        Route::get('/closed-items', [DailyQuickInventoryController::class, 'getClosedOrderItems'])->name('inventory.daily-quick.closed-items');
        Route::get('/employees', [DailyQuickInventoryController::class, 'getEmployees'])->name('inventory.daily-quick.employees');

        // Session management
        Route::post('/sessions', [DailyQuickInventoryController::class, 'createSession'])->name('inventory.daily-quick.sessions.create');
        Route::get('/sessions', [DailyQuickInventoryController::class, 'getSessions'])->name('inventory.daily-quick.sessions.index');
        Route::get('/sessions/{id}', [DailyQuickInventoryController::class, 'getSession'])->name('inventory.daily-quick.sessions.show');
        Route::put('/sessions/{id}', [DailyQuickInventoryController::class, 'updateSession'])->name('inventory.daily-quick.sessions.update');
        Route::delete('/sessions/{id}', [DailyQuickInventoryController::class, 'deleteSession'])->name('inventory.daily-quick.sessions.delete');
        Route::post('/sessions/{id}/submit', [DailyQuickInventoryController::class, 'submitSession'])->name('inventory.daily-quick.sessions.submit');
        Route::get('/sessions/{id}/summary', [DailyQuickInventoryController::class, 'getSessionSummary'])->name('inventory.daily-quick.sessions.summary');

        // Item management
        Route::post('/sessions/{id}/items', [DailyQuickInventoryController::class, 'addItem'])->name('inventory.daily-quick.sessions.items.create');
        Route::put('/sessions/{sessionId}/items/{itemId}', [DailyQuickInventoryController::class, 'updateItem'])->name('inventory.daily-quick.sessions.items.update');
        Route::delete('/sessions/{sessionId}/items/{itemId}', [DailyQuickInventoryController::class, 'removeItem'])->name('inventory.daily-quick.sessions.items.delete');
    });
});
