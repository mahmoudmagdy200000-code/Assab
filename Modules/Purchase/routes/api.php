<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchase\Http\Controllers\PurchaseOrderController;
use Modules\Purchase\Http\Controllers\GoodsReceiptController;
use Modules\Purchase\Http\Controllers\PurchaseReturnController;
use Modules\Purchase\Http\Controllers\PriceComparisonController;

Route::prefix('purchase')->middleware(['auth:sanctum'])->group(function () {

    // Purchase Orders
    Route::prefix('orders')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('purchase.orders.index');
        Route::get('/pending', [PurchaseOrderController::class, 'pending'])->name('purchase.orders.pending');
        Route::post('/', [PurchaseOrderController::class, 'store'])->name('purchase.orders.store');
        Route::get('/{id}', [PurchaseOrderController::class, 'show'])->name('purchase.orders.show');
        Route::put('/{id}', [PurchaseOrderController::class, 'update'])->name('purchase.orders.update');
        Route::post('/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase.orders.cancel');
        Route::post('/{id}/approve-modification', [PurchaseOrderController::class, 'approveModification'])->name('purchase.orders.approve-modification');
        Route::post('/{id}/reject-modification', [PurchaseOrderController::class, 'rejectModification'])->name('purchase.orders.reject-modification');
        Route::get('/{id}/timeline', [PurchaseOrderController::class, 'timeline'])->name('purchase.orders.timeline');
        Route::post('/{id}/change-source', [PurchaseOrderController::class, 'changeSource'])->name('purchase.orders.change-source');
    });

    // Goods Receipts
    Route::prefix('receipts')->group(function () {
        Route::get('/', [GoodsReceiptController::class, 'index'])->name('purchase.receipts.index');
        Route::post('/', [GoodsReceiptController::class, 'store'])->name('purchase.receipts.store');
        Route::get('/{id}', [GoodsReceiptController::class, 'show'])->name('purchase.receipts.show');
        Route::post('/draft', [GoodsReceiptController::class, 'saveDraft'])->name('purchase.receipts.save-draft');
        Route::post('/{id}/complete', [GoodsReceiptController::class, 'complete'])->name('purchase.receipts.complete');
        Route::post('/{id}/handle-variance', [GoodsReceiptController::class, 'handleVariance'])->name('purchase.receipts.handle-variance');
    });

    // Purchase Returns
    Route::prefix('returns')->group(function () {
        Route::get('/', [PurchaseReturnController::class, 'index'])->name('purchase.returns.index');
        Route::post('/', [PurchaseReturnController::class, 'store'])->name('purchase.returns.store');
        Route::get('/{id}', [PurchaseReturnController::class, 'show'])->name('purchase.returns.show');
        Route::post('/{id}/accept-rejection', [PurchaseReturnController::class, 'acceptRejection'])->name('purchase.returns.accept-rejection');
        Route::post('/{id}/escalate-rejection', [PurchaseReturnController::class, 'escalateRejection'])->name('purchase.returns.escalate-rejection');
        Route::get('/{id}/timeline', [PurchaseReturnController::class, 'timeline'])->name('purchase.returns.timeline');
    });

    // Price Comparison
    Route::prefix('price-comparison')->group(function () {
        Route::post('/compare', [PriceComparisonController::class, 'compare'])->name('purchase.price-comparison.compare');
        Route::get('/trends', [PriceComparisonController::class, 'trends'])->name('purchase.price-comparison.trends');
    });
});
