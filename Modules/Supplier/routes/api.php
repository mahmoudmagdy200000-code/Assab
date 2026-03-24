<?php

use Illuminate\Support\Facades\Route;
use Modules\Supplier\Http\Controllers\SupplierController;
use Modules\Supplier\Http\Controllers\Auth\AuthController;
use Modules\Supplier\Http\Controllers\Auth\PasswordResetController;
use Modules\Supplier\Http\Controllers\OrderController;
use Modules\Supplier\Http\Controllers\PendingOrderController;

/*
|--------------------------------------------------------------------------
| Supplier Module API Routes
|--------------------------------------------------------------------------
*/

// Public authentication routes
Route::prefix('v1/supplier')->group(function () {
    // Authentication
    Route::post('/auth/first-login', [AuthController::class, 'firstLogin'])->middleware(['throttle:supplier-auth', 'log.throttle']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware(['throttle:supplier-auth', 'log.throttle']);

    // Password Reset
    Route::post('/auth/password/reset/send-otp', [PasswordResetController::class, 'sendOTP'])->middleware(['throttle:supplier-auth', 'log.throttle']);
    Route::post('/auth/password/reset/verify-otp', [PasswordResetController::class, 'verifyOTP'])->middleware(['throttle:supplier-auth', 'log.throttle']);
    Route::post('/auth/password/reset', [PasswordResetController::class, 'resetPassword'])->middleware(['throttle:supplier-auth', 'log.throttle']);
});

// Protected routes - require supplier authentication
Route::middleware(['auth:sanctum', \Modules\Supplier\Http\Middleware\SupplierMiddleware::class, 'log.throttle'])->prefix('v1/supplier')->group(function () {
    // Authentication
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/password/reset/first-login', [AuthController::class, 'resetPasswordFirstLogin']);
    Route::post('/auth/password/change', [AuthController::class, 'changePassword']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/dashboard', [OrderController::class, 'dashboard']);
    Route::get('/orders/supplier-items', [OrderController::class, 'getSupplierItems']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{id}/accept', [OrderController::class, 'accept'])->middleware('throttle:purchase-write');
    Route::post('/orders/{id}/reject', [OrderController::class, 'reject'])->middleware('throttle:purchase-write');
    Route::post('/orders/{id}/request-modification', [OrderController::class, 'requestModification'])->middleware('throttle:purchase-write');

    // Pending Orders (Supplier Actions)
    Route::prefix('pending-orders')->group(function () {
        Route::get('/', [PendingOrderController::class, 'index']);
        Route::get('/{id}', [PendingOrderController::class, 'show']);
        Route::get('/{id}/timeline', [PendingOrderController::class, 'timeline']);

        // Approve actions
        Route::post('/{id}/approve', [PendingOrderController::class, 'approve']);
        Route::post('/{id}/partial-approve', [PendingOrderController::class, 'partialApprove']);
        Route::post('/{id}/approve-modifications', [PendingOrderController::class, 'approveModifications']);
        Route::post('/{id}/reject-modifications', [PendingOrderController::class, 'rejectModifications']);

        // Item-level approval requests
        Route::post('/{id}/items/{itemId}/request-time-change', [PendingOrderController::class, 'requestTimeChange'])->name('supplier.pending-orders.request-time-change');
        Route::post('/{id}/items/{itemId}/request-alternative', [PendingOrderController::class, 'requestAlternative'])->name('supplier.pending-orders.request-alternative');
        Route::post('/{id}/items/{itemId}/confirm', [PendingOrderController::class, 'confirmItem'])->name('supplier.pending-orders.confirm-item');
        Route::post('/{id}/items/{itemId}/reject', [PendingOrderController::class, 'rejectItem'])->name('supplier.pending-orders.reject-item');
        Route::post('/{id}/items/{itemId}/cancel', [PendingOrderController::class, 'cancelItem'])->name('supplier.pending-orders.cancel-item');

        // Approval requests (for needs_approval_supplier status)
        Route::post('/{id}/items/{itemId}/approve-request', [PendingOrderController::class, 'approveItemRequest'])->name('supplier.pending-orders.approve-item-request');

        // Modification details and cancellation reason
        Route::get('/{id}/items/{itemId}/modification', [PendingOrderController::class, 'getModificationDetails'])->name('supplier.pending-orders.get-modification');
        Route::get('/{id}/items/{itemId}/cancellation-reason', [PendingOrderController::class, 'getCancellationReason'])->name('supplier.pending-orders.get-cancellation-reason');

        // Status updates
        Route::post('/{id}/mark-preparing', [PendingOrderController::class, 'markAsPreparing']);
        Route::post('/mark-on-the-way', [PendingOrderController::class, 'markAsOnTheWay']);
        Route::post('/report-delay', [PendingOrderController::class, 'reportDelay']);
    });

    // Order Fulfillment
    Route::post('/fulfillment/start-preparation', [\Modules\Supplier\Http\Controllers\OrderFulfillmentController::class, 'startPreparation']);
    Route::put('/fulfillment/orders/{id}/update-preparation', [\Modules\Supplier\Http\Controllers\OrderFulfillmentController::class, 'updatePreparation']);
    Route::post('/fulfillment/orders/{id}/start-delivery', [\Modules\Supplier\Http\Controllers\OrderFulfillmentController::class, 'startDelivery']);
    Route::post('/fulfillment/orders/{id}/report-delay', [\Modules\Supplier\Http\Controllers\OrderFulfillmentController::class, 'reportDelay']);
    Route::post('/fulfillment/orders/{id}/complete', [\Modules\Supplier\Http\Controllers\OrderFulfillmentController::class, 'completeDelivery']);
    Route::post('/fulfillment/orders/{id}/submit-invoice', [\Modules\Supplier\Http\Controllers\OrderFulfillmentController::class, 'submitInvoice']);

    // Delivery Proofs
    Route::get('/fulfillment/orders/{id}/delivery-proof', [\Modules\Supplier\Http\Controllers\DeliveryProofController::class, 'show']);
    Route::put('/fulfillment/orders/{id}/delivery-proof', [\Modules\Supplier\Http\Controllers\DeliveryProofController::class, 'update']);

    // Inventory
    Route::get('/inventory/products', [\Modules\Supplier\Http\Controllers\InventoryController::class, 'getProducts']);
    Route::post('/inventory/products', [\Modules\Supplier\Http\Controllers\InventoryController::class, 'createProduct']);
    Route::put('/inventory/products/{id}', [\Modules\Supplier\Http\Controllers\InventoryController::class, 'updateProduct']);
    Route::put('/inventory/stock/{id}', [\Modules\Supplier\Http\Controllers\InventoryController::class, 'updateStock']);
    Route::get('/inventory/low-stock-alerts', [\Modules\Supplier\Http\Controllers\InventoryController::class, 'getLowStockAlerts']);

    // Communication
    Route::get('/communications/messages', [\Modules\Supplier\Http\Controllers\CommunicationController::class, 'getMessages']);
    Route::post('/communications/messages', [\Modules\Supplier\Http\Controllers\CommunicationController::class, 'sendMessage']);
    Route::post('/communications/messages/{id}/read', [\Modules\Supplier\Http\Controllers\CommunicationController::class, 'markAsRead']);
    Route::get('/communications/notifications', [\Modules\Supplier\Http\Controllers\CommunicationController::class, 'getNotifications']);
    Route::post('/communications/notifications/{id}/read', [\Modules\Supplier\Http\Controllers\CommunicationController::class, 'markNotificationAsRead']);
    Route::get('/communications/emergency-contacts', [\Modules\Supplier\Http\Controllers\CommunicationController::class, 'getEmergencyContacts']);
    Route::post('/communications/emergency-contacts', [\Modules\Supplier\Http\Controllers\CommunicationController::class, 'createEmergencyContact']);
    Route::post('/communications/escalate', [\Modules\Supplier\Http\Controllers\CommunicationController::class, 'escalateIssue']);

    // Analytics
    Route::get('/analytics/orders', [\Modules\Supplier\Http\Controllers\AnalyticsController::class, 'getOrderStatistics']);
    Route::get('/analytics/financial', [\Modules\Supplier\Http\Controllers\AnalyticsController::class, 'getFinancialReport']);
    Route::get('/analytics/performance', [\Modules\Supplier\Http\Controllers\AnalyticsController::class, 'getPerformanceMetrics']);
    Route::get('/analytics/customer-feedback', [\Modules\Supplier\Http\Controllers\AnalyticsController::class, 'getCustomerFeedback']);

    // Reporting
    Route::get('/reports/performance', [\Modules\Supplier\Http\Controllers\ReportingController::class, 'generatePerformanceReport']);
    Route::get('/reports/financial', [\Modules\Supplier\Http\Controllers\ReportingController::class, 'generateFinancialReport']);

    // Returns
    Route::get('/returns', [\Modules\Supplier\Http\Controllers\ReturnManagementController::class, 'getReturnRequests']);
    Route::get('/returns/{id}', [\Modules\Supplier\Http\Controllers\ReturnManagementController::class, 'viewReturnDetails']);
    Route::post('/returns/{id}/approve', [\Modules\Supplier\Http\Controllers\ReturnManagementController::class, 'approveReturn']);
    Route::post('/returns/{id}/reject', [\Modules\Supplier\Http\Controllers\ReturnManagementController::class, 'rejectReturn']);
    Route::post('/returns/{id}/process', [\Modules\Supplier\Http\Controllers\ReturnManagementController::class, 'processReturn']);

    // Recurring Orders
    Route::get('/recurring-orders', [\Modules\Supplier\Http\Controllers\RecurringOrderController::class, 'getRecurringOrders']);
    Route::put('/recurring-orders/{id}/schedule', [\Modules\Supplier\Http\Controllers\RecurringOrderController::class, 'modifySchedule']);

    // Emergency Orders
    Route::get('/emergency-orders', [\Modules\Supplier\Http\Controllers\EmergencyOrderController::class, 'getEmergencyOrders']);
    Route::post('/emergency-orders/{id}/respond', [\Modules\Supplier\Http\Controllers\EmergencyOrderController::class, 'respondToEmergency']);

    // Quality Assurance
    Route::get('/quality-assurance/documents', [\Modules\Supplier\Http\Controllers\QualityAssuranceController::class, 'getDocuments']);
    Route::post('/quality-assurance/documents', [\Modules\Supplier\Http\Controllers\QualityAssuranceController::class, 'uploadDocument']);
    Route::post('/quality-assurance/incidents', [\Modules\Supplier\Http\Controllers\QualityAssuranceController::class, 'logIncident']);

    // Settings
    Route::get('/settings/profile', [\Modules\Supplier\Http\Controllers\SettingsController::class, 'getProfile']);
    Route::put('/settings/profile', [\Modules\Supplier\Http\Controllers\SettingsController::class, 'updateProfile']);
    Route::put('/settings/account', [\Modules\Supplier\Http\Controllers\SettingsController::class, 'updateAccountSettings']);
    Route::put('/settings/system', [\Modules\Supplier\Http\Controllers\SettingsController::class, 'updateSystemSettings']);
    Route::put('/settings/notifications', [\Modules\Supplier\Http\Controllers\SettingsController::class, 'updateNotificationSettings']);
});

// Admin/System routes (for managing suppliers)
Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('suppliers', SupplierController::class)->names('supplier');
});
