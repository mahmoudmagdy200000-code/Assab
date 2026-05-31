<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\Accountant\AccountantController;
use Modules\Admin\Http\Controllers\Accountant\AssetController;
use Modules\Admin\Http\Controllers\Accountant\CashCustodyController;
use Modules\Admin\Http\Controllers\Accountant\EmployeeController;
use Modules\Admin\Http\Controllers\Accountant\InventoryController;
use Modules\Admin\Http\Controllers\Accountant\ReminderController;
use Modules\Admin\Http\Controllers\Accountant\ShiftController;
use Modules\Admin\Http\Controllers\Accountant\WasteController;
use Modules\Admin\Http\Controllers\Admin\AuditLogController;
use Modules\Admin\Http\Controllers\Admin\UploadController as AdminUploadController;
use Modules\Admin\Http\Controllers\Admin\BranchController;
use Modules\Admin\Http\Controllers\Admin\BrandController;
use Modules\Admin\Http\Controllers\Admin\CompanyController;
use Modules\Admin\Http\Controllers\Admin\DistributionController;
use Modules\Admin\Http\Controllers\Admin\OverviewController;
use Modules\Admin\Http\Controllers\Admin\PermissionMatrixController;
use Modules\Admin\Http\Controllers\Admin\RestaurantController;
use Modules\Admin\Http\Controllers\Admin\SettingsController;
use Modules\Admin\Http\Controllers\Admin\SubscriptionController;
use Modules\Admin\Http\Controllers\Admin\UserController;
use Modules\Admin\Http\Controllers\Auth\AuthController;
use Modules\Admin\Http\Controllers\Branch\BranchDashboardController;
use Modules\Admin\Http\Controllers\Head\HeadController;
use Modules\Admin\Http\Controllers\Operations\OperationController;
use Modules\Admin\Http\Controllers\Procurement\ProcurementController;
use Modules\Admin\Http\Controllers\Shared\ErpController;
use Modules\Admin\Http\Controllers\Shared\ExceptionController;
use Modules\Admin\Http\Controllers\Shared\LookupController;
use Modules\Admin\Http\Controllers\Shared\NotificationController;
use Modules\Admin\Http\Controllers\Shared\PipelineController;
use Modules\Admin\Http\Controllers\Shared\ReportController;
use Modules\Admin\Http\Controllers\Shared\SearchController;
use Modules\Admin\Http\Controllers\Shared\UploadController;
use Modules\Admin\Http\Controllers\Supplier\SupplierController;

/*
 | ASAB API — spec base /api/v1 (module RouteServiceProvider adds the /api prefix).
 | Response shapes follow BACKEND_API_SPEC.md exactly (handled by AsabResponse).
 */
Route::prefix('v1')->group(function () {

    // ---- Auth (§4) ----
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::get('auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{id}', [AuthController::class, 'deleteSession']);

        // ---- Admin (أمين النظام; §6.1) ----
        Route::middleware(['asab.tenant', 'asab.role:admin', 'asab.idempotency', 'asab.audit'])
            ->prefix('admin')
            ->group(function () {
                Route::get('overview', [OverviewController::class, 'index']);

                // Companies
                Route::get('companies', [CompanyController::class, 'index']);
                Route::post('companies', [CompanyController::class, 'store']);
                Route::get('companies/{id}', [CompanyController::class, 'show']);
                Route::patch('companies/{id}', [CompanyController::class, 'update']);
                Route::delete('companies/{id}', [CompanyController::class, 'destroy']);
                Route::post('companies/{id}/suspend', [CompanyController::class, 'suspend']);
                Route::post('companies/{id}/activate', [CompanyController::class, 'activate']);
                Route::post('companies/{id}/upgrade', [CompanyController::class, 'upgrade']);
                Route::get('companies/{id}/modules', [CompanyController::class, 'modules']);
                Route::patch('companies/{id}/modules', [CompanyController::class, 'updateModules']);
                Route::get('companies/{id}/usage', [CompanyController::class, 'usage']);

                // Brands / Restaurants / Branches
                Route::get('brands', [BrandController::class, 'index']);
                Route::post('brands', [BrandController::class, 'store']);
                Route::patch('brands/{id}', [BrandController::class, 'update']);
                Route::delete('brands/{id}', [BrandController::class, 'destroy']);
                Route::post('brands/{brandId}/restaurants', [RestaurantController::class, 'store']);
                Route::patch('restaurants/{id}', [RestaurantController::class, 'update']);
                Route::delete('restaurants/{id}', [RestaurantController::class, 'destroy']);
                Route::get('restaurants/subscriptions', [SubscriptionController::class, 'restaurants']);
                Route::get('branches', [BranchController::class, 'index']);
                Route::post('restaurants/{restaurantId}/branches', [BranchController::class, 'store']);
                Route::patch('branches/{id}', [BranchController::class, 'update']);
                Route::delete('branches/{id}', [BranchController::class, 'destroy']);

                // Users
                Route::get('users', [UserController::class, 'index']);
                Route::post('users', [UserController::class, 'store']);
                Route::post('users/import', [UserController::class, 'import']);
                Route::patch('users/{id}', [UserController::class, 'update']);
                Route::delete('users/{id}', [UserController::class, 'destroy']);
                Route::post('users/{id}/activate', [UserController::class, 'activate']);
                Route::post('users/{id}/deactivate', [UserController::class, 'deactivate']);

                // Distribution
                Route::get('distribution', [DistributionController::class, 'index']);
                Route::post('distribution/assign-restaurant', [DistributionController::class, 'assignRestaurant']);
                Route::delete('distribution/assign-restaurant', [DistributionController::class, 'unassignRestaurant']);
                Route::post('distribution/assign-modules', [DistributionController::class, 'assignModules']);
                Route::post('distribution/move-to-head', [DistributionController::class, 'moveToHead']);

                // Subscriptions
                Route::get('subscriptions', [SubscriptionController::class, 'index']);
                Route::post('subscriptions/{id}/renew', [SubscriptionController::class, 'renew']);
                Route::post('subscriptions/{id}/change-plan', [SubscriptionController::class, 'changePlan']);
                Route::post('subscriptions/{id}/toggle-auto-reminder', [SubscriptionController::class, 'toggleAutoReminder']);
                Route::post('subscriptions/{id}/suspend', [SubscriptionController::class, 'suspend']);
                Route::post('subscriptions/{id}/activate', [SubscriptionController::class, 'activate']);

                // Permissions matrix
                Route::get('permissions', [PermissionMatrixController::class, 'index']);
                Route::put('permissions', [PermissionMatrixController::class, 'replace']);
                Route::patch('permissions/cell', [PermissionMatrixController::class, 'updateCell']);
                Route::post('permissions/clone', [PermissionMatrixController::class, 'clone']);

                // Audit + settings + reports
                Route::get('audit-logs', [AuditLogController::class, 'index']);
                Route::get('settings', [SettingsController::class, 'show']);
                Route::patch('settings', [SettingsController::class, 'update']);
                Route::get('reports/catalog', [ReportController::class, 'catalog']);
                Route::post('reports/generate', [ReportController::class, 'generate']);

                // Excel/CSV bulk uploads (§6.1.5)
                Route::post('brands/{brandId}/upload/{type}', [AdminUploadController::class, 'brandUpload']);
                Route::post('restaurants/{restaurantId}/upload/employees', [AdminUploadController::class, 'employees']);
                Route::post('branches/{branchId}/upload/fixed-assets', [AdminUploadController::class, 'fixedAssets']);
                Route::get('upload/templates/{type}', [AdminUploadController::class, 'template']);
                Route::get('brands/{brandId}/upload-status', [AdminUploadController::class, 'status']);
            });

        // ---- Shared (auth + tenant) ----
        Route::middleware('asab.tenant')->group(function () {

            // Approval pipeline (§5)
            Route::get('operations', [OperationController::class, 'index']);
            Route::post('operations/bulk-approve', [OperationController::class, 'bulkApprove'])->middleware('asab.role:accountant,head');
            Route::get('operations/{id}', [OperationController::class, 'show']);
            Route::get('operations/{id}/audit-trail', [OperationController::class, 'auditTrail']);
            Route::post('operations/{id}/approve', [OperationController::class, 'approve'])->middleware('asab.role:accountant,head');
            Route::post('operations/{id}/reject', [OperationController::class, 'reject'])->middleware('asab.role:accountant,head');
            Route::post('operations/{id}/final-approve', [OperationController::class, 'finalApprove'])->middleware('asab.role:head');
            Route::post('operations/{id}/conditional-approve', [HeadController::class, 'conditionalApprove'])->middleware('asab.role:head');
            Route::post('operations/{id}/correction', [OperationController::class, 'correction'])->middleware('asab.role:accountant,head');

            // ERP (§5 / §7.4)
            Route::post('erp/batches', [HeadController::class, 'erpCreateBatch'])->middleware('asab.role:head');
            Route::get('erp/batches/{batchId}/status', [ErpController::class, 'status']);
            Route::get('erp/batches/{batchId}/download.json', [ErpController::class, 'downloadJson']);
            Route::get('erp/batches/{batchId}/download.csv', [ErpController::class, 'downloadCsv']);
            Route::get('erp/batches/{batchId}/download.xlsx', [ErpController::class, 'downloadXlsx']);

            // Cross-cutting (§7)
            Route::get('pipeline/overview', [PipelineController::class, 'overview']);
            Route::get('modules/aggregation', [PipelineController::class, 'aggregation']);
            Route::get('exceptions', [ExceptionController::class, 'index']);
            Route::get('search', [SearchController::class, 'index']);

            Route::get('lookups/brands', [LookupController::class, 'brands']);
            Route::get('lookups/restaurants', [LookupController::class, 'restaurants']);
            Route::get('lookups/branches', [LookupController::class, 'branches']);
            Route::get('lookups/suppliers', [LookupController::class, 'suppliers']);
            Route::get('lookups/items', [LookupController::class, 'items']);
            Route::get('lookups/users', [LookupController::class, 'users']);
            Route::get('lookups/employees', [LookupController::class, 'employees']);
            Route::get('lookups/modules', [LookupController::class, 'modules']);
            Route::get('lookups/exceptions', [ExceptionController::class, 'index']);

            Route::get('notifications', [NotificationController::class, 'index']);
            Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
            Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

            // Uploads & attachments (§7.3)
            Route::post('uploads/presigned-url', [UploadController::class, 'presignedUrl']);
            Route::post('uploads/direct', [UploadController::class, 'direct']);
            Route::post('uploads/{attachmentId}/confirm', [UploadController::class, 'confirm']);
            Route::get('attachments/{id}', [UploadController::class, 'show']);
            Route::get('attachments/{id}/download', [UploadController::class, 'download']);
            Route::post('attachments/{id}/verify', [UploadController::class, 'verify']);
            Route::delete('attachments/{id}', [UploadController::class, 'destroy']);

            // Reports (§7.5)
            Route::post('reports/profit-loss', [ReportController::class, 'profitLoss']);
            Route::post('reports/sales-summary', [ReportController::class, 'salesSummary']);
            Route::post('reports/expense-summary', [ReportController::class, 'expenseSummary']);
            Route::post('reports/inventory-valuation', [ReportController::class, 'inventoryValuation']);
            Route::post('reports/payroll', [ReportController::class, 'payroll']);
            Route::post('reports/waste-analysis', [ReportController::class, 'wasteAnalysis']);
            Route::post('reports/supplier-performance', [ReportController::class, 'supplierPerformance']);
            Route::post('reports/menu-engineering', [ReportController::class, 'menuEngineering']);
            Route::post('reports/breakeven', [ReportController::class, 'breakeven']);
            Route::post('reports/cash-flow', [ReportController::class, 'cashFlow']);

            // ---- Head Accountant (رئيس الحسابات; §6.2) ----
            Route::middleware('asab.role:head')->prefix('head')->group(function () {
                Route::get('dashboard', [HeadController::class, 'dashboard']);
                Route::get('operations/pending', [HeadController::class, 'pending']);
                Route::get('operations/final-approved', [HeadController::class, 'finalApproved']);
                Route::get('operations/rejected', [HeadController::class, 'rejected']);
                Route::get('accountants/performance', [HeadController::class, 'accountantsPerformance']);
                Route::get('erp/preflight', [HeadController::class, 'erpPreflight']);
                Route::get('erp/eligible-operations', [HeadController::class, 'erpEligible']);
                Route::get('erp/batches', [HeadController::class, 'erpBatches']);
                Route::get('reports/internal', [HeadController::class, 'reportsInternal']);
                Route::get('reports/owner', [HeadController::class, 'reportsOwner']);
            });

            // ---- Accountant (المحاسب; §6.3) ----
            Route::middleware('asab.role:accountant,head')->prefix('accountant')->group(function () {
                Route::get('dashboard', [AccountantController::class, 'dashboard']);
                Route::get('operations', [AccountantController::class, 'operations']);
                Route::patch('operations/{id}/reconciliation', [AccountantController::class, 'reconciliation']);
                Route::post('expense-invoices/{invoiceId}/convert-to-asset', [AccountantController::class, 'convertToAsset']);

                Route::get('assets', [AssetController::class, 'index']);
                Route::post('assets', [AssetController::class, 'store']);
                Route::post('assets/{id}/confirm', [AssetController::class, 'confirm']);
                Route::get('asset-drafts', [AssetController::class, 'drafts']);
                Route::post('asset-drafts/{draftId}/confirm', [AssetController::class, 'confirmDraft']);
                Route::delete('asset-drafts/{draftId}', [AssetController::class, 'discardDraft']);

                // Inventory (§6.3.5–6.3.6)
                Route::get('inventory', [InventoryController::class, 'index']);
                Route::post('inventory/branches/{branchId}/flag', [InventoryController::class, 'flagBranch']);
                Route::post('inventory/branches/{branchId}/items/flag', [InventoryController::class, 'flagItems']);
                Route::post('inventory/branches/{branchId}/send-confirmation', [InventoryController::class, 'sendConfirmation']);
                Route::get('inventory/catalog', [InventoryController::class, 'catalog']);
                Route::post('inventory/catalog', [InventoryController::class, 'storeCatalogItem']);
                Route::get('inventory/branches/{branchId}/daily-list', [InventoryController::class, 'dailyList']);
                Route::put('inventory/branches/{branchId}/daily-list', [InventoryController::class, 'saveDailyList']);

                // Waste (§6.3.7)
                Route::get('waste', [WasteController::class, 'index']);
                Route::patch('waste/{entryId}/products/{productIdx}', [WasteController::class, 'classifyProduct']);
                Route::put('waste/{entryId}/products/{productIdx}/allocations', [WasteController::class, 'allocations']);
                Route::post('waste/{entryId}/approve', [WasteController::class, 'approve']);
                Route::post('waste/{entryId}/reject', [WasteController::class, 'reject']);
                Route::post('waste/bulk-approve', [WasteController::class, 'bulkApprove']);

                // Shifts (§6.3.9)
                Route::get('shifts/live', [ShiftController::class, 'live']);
                Route::get('shifts/history', [ShiftController::class, 'history']);
                Route::post('shifts/{id}/close', [ShiftController::class, 'close']);

                // Employees (§6.3.10)
                Route::get('employees', [EmployeeController::class, 'index']);
                Route::get('employees/{id}/statement', [EmployeeController::class, 'statement']);
                Route::post('employees/{id}/movements', [EmployeeController::class, 'addMovement']);

                // Cash custody (§6.3.11)
                Route::get('cash-custody', [CashCustodyController::class, 'index']);
                Route::post('cash-custody/{id}/settlement-request', [CashCustodyController::class, 'settlementRequest']);
                Route::post('cash-custody/{id}/transactions', [CashCustodyController::class, 'addTransaction']);
            });

            // Reminders (§6.3.12) — accountant + head
            Route::middleware('asab.role:accountant,head')->group(function () {
                Route::get('reminders', [AccountantController::class, 'reminders']);
                Route::post('reminders/{id}/send', [ReminderController::class, 'send']);
                Route::post('reminders/bulk-send', [ReminderController::class, 'bulkSend']);
                Route::post('reminders/{id}/respond', [ReminderController::class, 'respond']);
                Route::get('reminders/rules', [ReminderController::class, 'rules']);
                Route::post('reminders/rules', [ReminderController::class, 'storeRule']);
                Route::patch('reminders/rules/{id}', [ReminderController::class, 'updateRule']);
                Route::delete('reminders/rules/{id}', [ReminderController::class, 'deleteRule']);
                Route::post('reminders/rules/{id}/toggle', [ReminderController::class, 'toggleRule']);
            });

            // ---- Branch Manager (مدير الفرع; §6.4) ----
            Route::middleware('asab.role:branch')->prefix('branch')->group(function () {
                Route::get('overview', [BranchDashboardController::class, 'overview']);
                Route::get('upload/status', [BranchDashboardController::class, 'uploadStatus']);
                Route::post('upload/{reportType}', [BranchDashboardController::class, 'upload']);
                Route::get('employees', [BranchDashboardController::class, 'employees']);
                Route::post('employees', [BranchDashboardController::class, 'storeEmployee']);
                Route::get('inventory-items', [BranchDashboardController::class, 'items']);
                Route::get('suppliers', [BranchDashboardController::class, 'suppliers']);
                Route::get('settings', [BranchDashboardController::class, 'settings']);
                Route::patch('settings', [BranchDashboardController::class, 'updateSettings']);
                Route::post('assets/{id}/confirm', [BranchDashboardController::class, 'confirmAsset']);
                Route::post('inventory/{id}/reconfirm', [BranchDashboardController::class, 'reconfirmInventory']);
            });

            // ---- Procurement (مدير المشتريات; §6.5) ----
            Route::middleware('asab.role:procurement')->prefix('procurement')->group(function () {
                Route::get('overview', [ProcurementController::class, 'overview']);
                Route::get('orders', [ProcurementController::class, 'orders']);
                Route::get('orders/{id}', [ProcurementController::class, 'show']);
                Route::post('orders/{id}/approve', [ProcurementController::class, 'approve']);
                Route::post('orders/{id}/reject', [ProcurementController::class, 'reject']);
                Route::post('orders/{id}/partial-reject', [ProcurementController::class, 'partialReject']);
                Route::post('orders/consolidate', [ProcurementController::class, 'consolidate']);
                Route::post('orders/{groupId}/send', [ProcurementController::class, 'send']);
                Route::get('suppliers', [ProcurementController::class, 'suppliers']);
                Route::get('items', [ProcurementController::class, 'items']);
            });

            // ---- Supplier (المورد; §6.6) ----
            // Namespaced under asab/supplier: the legacy Supplier module already owns
            // /api/v1/supplier/* (mobile portal, different auth guard). Keeping both.
            Route::middleware('asab.role:supplier')->prefix('asab/supplier')->group(function () {
                Route::get('overview', [SupplierController::class, 'overview']);
                Route::get('orders', [SupplierController::class, 'orders']);
                Route::post('orders/{id}/accept', [SupplierController::class, 'accept']);
                Route::post('orders/{id}/reject', [SupplierController::class, 'reject']);
                Route::post('orders/{id}/mark-delivered', [SupplierController::class, 'markDelivered']);
                Route::get('items', [SupplierController::class, 'items']);
                Route::post('items', [SupplierController::class, 'storeItem']);
                Route::patch('items/{id}', [SupplierController::class, 'updateItem']);
                Route::post('items/{id}/toggle-active', [SupplierController::class, 'toggleItem']);
                Route::delete('items/{id}', [SupplierController::class, 'destroyItem']);
                Route::get('reports', [SupplierController::class, 'reports']);
            });
        });
    });
});
