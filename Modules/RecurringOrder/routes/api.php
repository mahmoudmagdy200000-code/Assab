<?php

use Illuminate\Support\Facades\Route;
use Modules\RecurringOrder\Http\Controllers\RecurringOrderController;

/*
|--------------------------------------------------------------------------
| Recurring Orders Management (3.1.2.6) - Branch Manager
|--------------------------------------------------------------------------
| Base path: api/v1/recurring-orders (prefix 'api' from RouteServiceProvider)
| Example: GET api/v1/recurring-orders/in-progress (not api/v1/in-progress)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->prefix('v1/recurring-orders')->group(function () {
    // 3.1.2.6.1 In Progress List
    Route::get('in-progress', [RecurringOrderController::class, 'indexInProgress'])->name('recurring-orders.in-progress');
    // 3.1.2.6.2 Next Scheduling List
    Route::get('next-scheduling', [RecurringOrderController::class, 'indexNextScheduling'])->name('recurring-orders.next-scheduling');
    // 3.1.2.6.3 Paused List
    Route::get('paused', [RecurringOrderController::class, 'indexPaused'])->name('recurring-orders.paused');

    // Add Recurring Order – form data
    Route::get('suppliers', [RecurringOrderController::class, 'suppliers'])->name('recurring-orders.suppliers');
    Route::get('purchasing-officers', [RecurringOrderController::class, 'purchasingOfficers'])->name('recurring-orders.purchasing-officers');

    // 3.1.2.6.1.1 Add Recurring Orders
    Route::post('/', [RecurringOrderController::class, 'store'])->name('recurring-orders.store');

    // View Details (3.1.2.6.1.2, 3.1.2.6.2.1, 3.1.2.6.3.1)
    Route::get('{id}', [RecurringOrderController::class, 'show'])->name('recurring-orders.show');

    // Actions
    Route::post('{id}/pause', [RecurringOrderController::class, 'pause'])->name('recurring-orders.pause');
    Route::post('{id}/resume', [RecurringOrderController::class, 'resume'])->name('recurring-orders.resume');
    Route::put('{id}', [RecurringOrderController::class, 'update'])->name('recurring-orders.update');
    Route::delete('{id}', [RecurringOrderController::class, 'destroy'])->name('recurring-orders.destroy');
});
