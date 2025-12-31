<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\DailyQuickInventoryController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('inventories', InventoryController::class)->names('inventory');

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
