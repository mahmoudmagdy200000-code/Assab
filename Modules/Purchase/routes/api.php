<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchase\Http\Controllers\GoodsReceivingController;
use Modules\Purchase\Http\Controllers\NewOrderController;
use Modules\Purchase\Http\Controllers\PendingOrderController;
use Modules\Purchase\Http\Controllers\PurchaseHistoryController;
use Modules\Purchase\Http\Controllers\ReturnManagementController;
use Modules\Purchase\Http\Controllers\SupplierController;

/*
|--------------------------------------------------------------------------
| Purchase Module API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with 'api/v1/purchase'
|
*/

Route::middleware(['auth:sanctum'])->prefix('v1/purchase')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Purchase History (3.1.2.4.1)
    |--------------------------------------------------------------------------
    */
    Route::prefix('history')->group(function () {
        Route::get('/', [PurchaseHistoryController::class, 'index'])->name('purchase.history.index');
        Route::get('/{id}', [PurchaseHistoryController::class, 'show'])->name('purchase.history.show');
        Route::get('/{id}/timeline', [PurchaseHistoryController::class, 'timeline'])->name('purchase.history.timeline');
    });

    /*
    |--------------------------------------------------------------------------
    | New Order (3.1.2.4.2)
    |--------------------------------------------------------------------------
    */
    Route::prefix('orders')->group(function () {

        // get branch items
        Route::get('/branch-items', [NewOrderController::class, 'getBranchItems'])->name('purchase.orders.branch-items');
        // Price comparison
        Route::post('/compare-prices', [NewOrderController::class, 'comparePrices'])->name('purchase.orders.compare-prices');

        // Source selection helpers
        Route::get('/suppliers', [NewOrderController::class, 'getSuppliers'])->name('purchase.orders.suppliers');
        Route::get('/branches', [NewOrderController::class, 'getBranches'])->name('purchase.orders.branches');

        // Transfer items (for internal transfer)
        Route::get('/transfer-items', [NewOrderController::class, 'getTransferItems'])->name('purchase.orders.transfer-items');

        // Direct Supplier Items
        Route::get('/direct-supplier-items', [NewOrderController::class, 'getDirectSupplierItems'])->name('purchase.orders.direct-supplier-items');

        // Supplier Items (by supplier_id)
        Route::get('/supplier-items', [NewOrderController::class, 'getSupplierItems'])->name('purchase.orders.supplier-items');

        // Purchasing Officer Items
        Route::get('/purchasing-officer-items', [NewOrderController::class, 'getPurchasingOfficerItems'])->name('purchase.orders.purchasing-officer-items');

        // Orders list and creation
        Route::get('/', [NewOrderController::class, 'index'])->name('purchase.orders.index');
        Route::post('/', [NewOrderController::class, 'store'])->name('purchase.orders.store');

        // Order actions
        Route::get('/{id}/summary', [NewOrderController::class, 'getSummary'])->name('purchase.orders.summary');
        Route::put('/{id}/items', [NewOrderController::class, 'updateItems'])->name('purchase.orders.update-items');
        Route::post('/{id}/submit', [NewOrderController::class, 'submit'])->name('purchase.orders.submit');
    });

    /*
    |--------------------------------------------------------------------------
    | Pending Orders (3.1.2.4.3)
    |--------------------------------------------------------------------------
    */
    Route::prefix('pending')->group(function () {
        Route::get('/', [PendingOrderController::class, 'index'])->name('purchase.pending.index');
        Route::get('/{id}', [PendingOrderController::class, 'show'])->name('purchase.pending.show');
        Route::get('/{id}/timeline', [PendingOrderController::class, 'timeline'])->name('purchase.pending.timeline');

        // Order actions
        Route::post('/{id}/approve', [PendingOrderController::class, 'approve'])->name('purchase.pending.approve');
        Route::post('/{id}/partial-approve', [PendingOrderController::class, 'partialApprove'])->name('purchase.pending.partial-approve');
        Route::post('/{id}/reject', [PendingOrderController::class, 'reject'])->name('purchase.pending.reject');
        Route::post('/{id}/cancel', [PendingOrderController::class, 'cancel'])->name('purchase.pending.cancel');

        // Transfer-specific actions
        Route::post('/{id}/approve-transfer', [PendingOrderController::class, 'approveTransfer'])->name('purchase.pending.approve-transfer');

        // Modification handling
        Route::post('/{id}/approve-modifications', [PendingOrderController::class, 'approveModifications'])->name('purchase.pending.approve-modifications');
        Route::post('/{id}/reject-modifications', [PendingOrderController::class, 'rejectModifications'])->name('purchase.pending.reject-modifications');

        // Item-level approval requests
        Route::post('/{id}/items/{itemId}/approve', [PendingOrderController::class, 'approveItemRequest'])->name('purchase.pending.approve-item');
        Route::post('/{id}/items/{itemId}/reject', [PendingOrderController::class, 'rejectItemRequest'])->name('purchase.pending.reject-item');
        Route::post('/{id}/items/{itemId}/cancel', [PendingOrderController::class, 'cancelItem'])->name('purchase.pending.cancel-item');

        // Modification details and actions
        Route::get('/{id}/items/{itemId}/modification', [PendingOrderController::class, 'getModificationDetails'])->name('purchase.pending.get-modification');
        Route::post('/{id}/items/{itemId}/modification/approve', [PendingOrderController::class, 'approveModification'])->name('purchase.pending.approve-modification');
        Route::post('/{id}/items/{itemId}/modification/reject', [PendingOrderController::class, 'rejectModification'])->name('purchase.pending.reject-modification');
        Route::get('/{id}/items/{itemId}/cancellation-reason', [PendingOrderController::class, 'getCancellationReason'])->name('purchase.pending.get-cancellation-reason');

        // Delay handling for orders
        Route::post('/{id}/delay/approve', [PendingOrderController::class, 'approveOrderDelay'])->name('purchase.pending.approve-order-delay');
        Route::post('/{id}/delay/reject', [PendingOrderController::class, 'rejectOrderDelay'])->name('purchase.pending.reject-order-delay');

        /*
        |--------------------------------------------------------------------------
        | Direct Supplier Orders (3.1.2.4.3.2.1)
        |--------------------------------------------------------------------------
        */
        Route::prefix('direct-supplier')->group(function () {
            Route::get('/', [PendingOrderController::class, 'directSupplierOrders'])->name('purchase.pending.direct-supplier.index');
            Route::get('/{id}', [PendingOrderController::class, 'directSupplierOrderDetails'])->name('purchase.pending.direct-supplier.show');
            Route::post('/{id}/approve-delay', [PendingOrderController::class, 'approveDelay'])->name('purchase.pending.direct-supplier.approve-delay');
            Route::post('/{id}/reject-delay', [PendingOrderController::class, 'rejectDelay'])->name('purchase.pending.direct-supplier.reject-delay');
        });

        /*
        |--------------------------------------------------------------------------
        | Via Purchasing Officer Orders (3.1.2.4.3.3.1)
        |--------------------------------------------------------------------------
        */
        Route::prefix('via-purchasing-officer')->group(function () {
            Route::get('/', [PendingOrderController::class, 'viaPurchasingOfficerOrders'])->name('purchase.pending.via-purchasing-officer.index');
            Route::get('/{id}', [PendingOrderController::class, 'viaPurchasingOfficerOrderDetails'])->name('purchase.pending.via-purchasing-officer.show');
            Route::post('/{id}/approve-delay', [PendingOrderController::class, 'approveDelay'])->name('purchase.pending.via-purchasing-officer.approve-delay');
            Route::post('/{id}/reject-delay', [PendingOrderController::class, 'rejectDelay'])->name('purchase.pending.via-purchasing-officer.reject-delay');
        });

        /*
        |--------------------------------------------------------------------------
        | Internal Transfer Orders (3.1.2.4.3.4.1)
        |--------------------------------------------------------------------------
        */
        Route::prefix('internal-transfer')->group(function () {
            Route::get('/', [PendingOrderController::class, 'internalTransferOrders'])->name('purchase.pending.internal-transfer.index');
            Route::get('/{id}', [PendingOrderController::class, 'internalTransferOrderDetails'])->name('purchase.pending.internal-transfer.show');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Goods Receiving (3.1.2.4.4)
    |--------------------------------------------------------------------------
    */
    Route::prefix('receiving')->group(function () {
        // Lists
        Route::get('/in-progress', [GoodsReceivingController::class, 'inProgress'])->name('purchase.receiving.in-progress');
        Route::get('/orders', [GoodsReceivingController::class, 'ordersForReceiving'])->name('purchase.receiving.orders');
        Route::get('/drafts', [GoodsReceivingController::class, 'drafts'])->name('purchase.receiving.drafts');
        Route::get('/missing', [GoodsReceivingController::class, 'missingGoods'])->name('purchase.receiving.missing');
        Route::get('/completed', [GoodsReceivingController::class, 'completed'])->name('purchase.receiving.completed');

        // Receive without prior order
        Route::post('/without-order', [GoodsReceivingController::class, 'receiveWithoutOrder'])->name('purchase.receiving.without-order');

        // Start receiving
        Route::post('/orders/{orderId}/start', [GoodsReceivingController::class, 'startReceiving'])->name('purchase.receiving.start');

        // Receive internal transfer
        Route::post('/orders/{orderId}/receive-internal-transfer', [GoodsReceivingController::class, 'receiveInternalTransfer'])->name('purchase.receiving.receive-internal-transfer');

        // Receipt management
        Route::get('/{id}', [GoodsReceivingController::class, 'show'])->name('purchase.receiving.show');
        Route::get('/{id}/summary', [GoodsReceivingController::class, 'getReceiptSummary'])->name('purchase.receiving.summary');
        Route::put('/{id}/delivery-details', [GoodsReceivingController::class, 'updateDeliveryDetails'])->name('purchase.receiving.delivery-details');
        Route::post('/{id}/items/{itemId}/inspect', [GoodsReceivingController::class, 'inspectItem'])->name('purchase.receiving.inspect-item');
        Route::post('/{id}/unlisted-item', [GoodsReceivingController::class, 'addUnlistedItem'])->name('purchase.receiving.add-unlisted');
        Route::put('/{id}/document-type', [GoodsReceivingController::class, 'setDocumentType'])->name('purchase.receiving.document-type');
        Route::post('/{id}/invoice', [GoodsReceivingController::class, 'createInvoice'])->name('purchase.receiving.invoice');
        Route::post('/{id}/delivery-note', [GoodsReceivingController::class, 'createDeliveryNote'])->name('purchase.receiving.delivery-note');
        Route::post('/{id}/receipt-without-document', [GoodsReceivingController::class, 'createReceiptWithoutDocument'])->name('purchase.receiving.receipt-without-document');
        Route::post('/{id}/complete', [GoodsReceivingController::class, 'completeInspection'])->name('purchase.receiving.complete');
        Route::post('/{id}/save-draft', [GoodsReceivingController::class, 'saveDraft'])->name('purchase.receiving.save-draft');
        Route::delete('/{id}/draft', [GoodsReceivingController::class, 'deleteDraft'])->name('purchase.receiving.delete-draft');

        // Draft details
        Route::get('/drafts/{id}', [GoodsReceivingController::class, 'getDraftDetails'])->name('purchase.receiving.draft-details');

        // Missing goods details
        Route::get('/missing/{id}', [GoodsReceivingController::class, 'getMissingGoodsDetails'])->name('purchase.receiving.missing-details');

        // Complete goods details
        Route::get('/completed/{id}', [GoodsReceivingController::class, 'getCompleteGoodsDetails'])->name('purchase.receiving.completed-details');

        // Order tracking
        Route::get('/orders/{orderId}/tracking', [GoodsReceivingController::class, 'getOrderTracking'])->name('purchase.receiving.tracking');
        
        // Get inspection details by order ID
        Route::get('/orders/{orderId}/inspection', [GoodsReceivingController::class, 'getInspectionDetailsByOrderId'])->name('purchase.receiving.inspection-by-order');

        // Variance handling
        Route::post('/variances/{varianceId}/action', [GoodsReceivingController::class, 'handleVariance'])->name('purchase.receiving.variance-action');
        Route::post('/variances/{varianceId}/supplier-response', [GoodsReceivingController::class, 'handleSupplierResponse'])->name('purchase.receiving.supplier-response');
        Route::post('/variances/{varianceId}/accept-rejection', [GoodsReceivingController::class, 'acceptRejection'])->name('purchase.receiving.accept-rejection');
        Route::post('/variances/{varianceId}/escalate', [GoodsReceivingController::class, 'escalateRejection'])->name('purchase.receiving.escalate-rejection');
    });

    /*
    |--------------------------------------------------------------------------
    | Return Management (3.1.2.4.5)
    |--------------------------------------------------------------------------
    */
    Route::prefix('returns')->group(function () {
        // Lists
        Route::get('/in-progress', [ReturnManagementController::class, 'inProgress'])->name('purchase.returns.in-progress');
        Route::get('/drafts', [ReturnManagementController::class, 'drafts'])->name('purchase.returns.drafts');
        Route::get('/completed', [ReturnManagementController::class, 'completed'])->name('purchase.returns.completed');

        // CRUD
        Route::post('/', [ReturnManagementController::class, 'store'])->name('purchase.returns.store');
        Route::get('/{id}', [ReturnManagementController::class, 'show'])->name('purchase.returns.show');
        Route::put('/{id}', [ReturnManagementController::class, 'update'])->name('purchase.returns.update');
        Route::get('/{id}/timeline', [ReturnManagementController::class, 'timeline'])->name('purchase.returns.timeline');

        // Actions
        Route::post('/{id}/submit', [ReturnManagementController::class, 'submit'])->name('purchase.returns.submit');
        Route::post('/{id}/accept-rejection', [ReturnManagementController::class, 'acceptRejection'])->name('purchase.returns.accept-rejection');
        Route::post('/{id}/escalate', [ReturnManagementController::class, 'escalate'])->name('purchase.returns.escalate');

        // Draft management
        Route::post('/draft', [ReturnManagementController::class, 'saveDraft'])->name('purchase.returns.save-draft');
        Route::delete('/{id}/draft', [ReturnManagementController::class, 'deleteDraft'])->name('purchase.returns.delete-draft');
    });

    /*
    |--------------------------------------------------------------------------
    | Suppliers
    |--------------------------------------------------------------------------
    */
    Route::prefix('suppliers')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('purchase.suppliers.index');
        Route::get('/{id}', [SupplierController::class, 'show'])->name('purchase.suppliers.show');
        Route::get('/{id}/items', [SupplierController::class, 'items'])->name('purchase.suppliers.items');
        Route::get('/{id}/orders', [SupplierController::class, 'orderHistory'])->name('purchase.suppliers.orders');
        Route::get('/{id}/statistics', [SupplierController::class, 'statistics'])->name('purchase.suppliers.statistics');
    });
});
