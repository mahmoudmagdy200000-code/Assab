<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\Accountant\AccountantController;
use Modules\Admin\Http\Controllers\Accountant\AssetController;
use Modules\Admin\Http\Controllers\Accountant\CashCustodyController;
use Modules\Admin\Http\Controllers\Accountant\EmployeeController;
use Modules\Admin\Http\Controllers\Accountant\InventoryController;
use Modules\Admin\Http\Controllers\Accountant\PurchaseReturnController;
use Modules\Admin\Http\Controllers\Accountant\ReminderController;
use Modules\Admin\Http\Controllers\Accountant\ShiftController;
use Modules\Admin\Http\Controllers\Accountant\WasteController;
use Modules\Admin\Http\Controllers\Admin\AuditLogController;
use Modules\Admin\Http\Controllers\Admin\BranchController;
use Modules\Admin\Http\Controllers\Admin\BrandController;
use Modules\Admin\Http\Controllers\Admin\CompanyController;
use Modules\Admin\Http\Controllers\Admin\DistributionController;
use Modules\Admin\Http\Controllers\Admin\JobMonitorController;
use Modules\Admin\Http\Controllers\Admin\OverviewController;
use Modules\Admin\Http\Controllers\Admin\PackageController;
use Modules\Admin\Http\Controllers\Admin\PermissionMatrixController;
use Modules\Admin\Http\Controllers\Admin\RestaurantController;
use Modules\Admin\Http\Controllers\Admin\SettingsController;
use Modules\Admin\Http\Controllers\Admin\SubscriptionController;
use Modules\Admin\Http\Controllers\Admin\UploadController as AdminUploadController;
use Modules\Admin\Http\Controllers\Admin\UserController;
use Modules\Admin\Http\Controllers\Auth\AuthController;
use Modules\Admin\Http\Controllers\Auth\SsoController as AuthSsoController;
use Modules\Admin\Http\Controllers\Auth\TwoFactorController;
use Modules\Admin\Http\Controllers\Branch\BranchDashboardController;
use Modules\Admin\Http\Controllers\Company\AccountantCompanyController;
use Modules\Admin\Http\Controllers\Company\ApiKeyController;
use Modules\Admin\Http\Controllers\Company\BillingController as CompanyBillingController;
use Modules\Admin\Http\Controllers\Company\BranchCompanyController;
use Modules\Admin\Http\Controllers\Company\CrossController;
use Modules\Admin\Http\Controllers\Company\DashboardController as CompanyDashboardController;
use Modules\Admin\Http\Controllers\Company\ExportController as CompanyExportController;
use Modules\Admin\Http\Controllers\Company\HeadCompanyController;
use Modules\Admin\Http\Controllers\Company\ModuleController as CompanyModuleController;
use Modules\Admin\Http\Controllers\Company\OnboardController;
use Modules\Admin\Http\Controllers\Company\OrgController as CompanyOrgController;
use Modules\Admin\Http\Controllers\Company\PersonalReminderController;
use Modules\Admin\Http\Controllers\Company\ProcurementCompanyController;
use Modules\Admin\Http\Controllers\Company\SettingsController as CompanySettingsController;
use Modules\Admin\Http\Controllers\Company\SsoController as CompanySsoController;
use Modules\Admin\Http\Controllers\Company\SubscriptionController as CompanySubscriptionController;
use Modules\Admin\Http\Controllers\Company\SupportChatController;
use Modules\Admin\Http\Controllers\Company\SupportController as CompanySupportController;
use Modules\Admin\Http\Controllers\Company\TenantWebhookController;
use Modules\Admin\Http\Controllers\Company\UserController as CompanyUserController;
use Modules\Admin\Http\Controllers\Company\WebhookController;
use Modules\Admin\Http\Controllers\Head\HeadController;
use Modules\Admin\Http\Controllers\Operations\OperationController;
use Modules\Admin\Http\Controllers\Procurement\ProcurementController;
use Modules\Admin\Http\Controllers\Procurement\ProcurementPurchaseOrderController;
use Modules\Admin\Http\Controllers\Shared\DataPrivacyController;
use Modules\Admin\Http\Controllers\Shared\ErpController;
use Modules\Admin\Http\Controllers\Shared\ExceptionController;
use Modules\Admin\Http\Controllers\Shared\LookupController;
use Modules\Admin\Http\Controllers\Shared\NotificationController;
use Modules\Admin\Http\Controllers\Shared\OnboardingController;
use Modules\Admin\Http\Controllers\Shared\PipelineController;
use Modules\Admin\Http\Controllers\Shared\QuickStatsController;
use Modules\Admin\Http\Controllers\Shared\ReportBuilderController;
use Modules\Admin\Http\Controllers\Shared\ReportController;
use Modules\Admin\Http\Controllers\Shared\SavedFilterController;
use Modules\Admin\Http\Controllers\Shared\SearchController;
use Modules\Admin\Http\Controllers\Shared\TablePrefController;
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
    Route::post('auth/forgot-password/resend', [AuthController::class, 'forgotPasswordResend']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

    // Company invitation acceptance — public (token-authenticated; §4.2)
    Route::post('company/invitations/accept', [OnboardController::class, 'acceptInvitation']);

    // Payment-gateway webhooks — public, provider-signature verified (§6)
    Route::post('webhooks/{provider}', [WebhookController::class, 'handle'])
        ->where('provider', 'stripe|tap|hyperpay|moyasar');

    // SSO sign-in callback — public (FE completion request §3.2)
    Route::post('auth/sso/{provider}/callback', [AuthSsoController::class, 'callback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::get('auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{id}', [AuthController::class, 'deleteSession']);

        // Two-factor auth (§3.1) — account-level, any authenticated AsabUser
        Route::post('auth/2fa/setup', [TwoFactorController::class, 'setup']);
        Route::post('auth/2fa/verify', [TwoFactorController::class, 'verify']);
        Route::post('auth/2fa/disable', [TwoFactorController::class, 'disable']);
        Route::get('users/me/2fa-status', [TwoFactorController::class, 'status']);

        // GDPR / Saudi PDPL self-service (§3.4)
        Route::post('users/me/data-export', [DataPrivacyController::class, 'requestExport']);
        Route::get('users/me/data-export/{jobId}', [DataPrivacyController::class, 'exportStatus']);
        Route::post('users/me/account-deletion-request', [DataPrivacyController::class, 'requestDeletion']);

        // ---- Admin (أمين النظام; §6.1) ----
        Route::middleware(['asab.tenant', 'asab.role:admin', 'asab.idempotency', 'asab.audit'])
            ->prefix('admin')
            ->group(function () {
                Route::get('overview', [OverviewController::class, 'index']);

                // ERP-2 admin export screen (§14.3): connection banner, KPIs,
                // cross-company batch log, select/all-ready bulk export.
                Route::get('erp/summary', [\Modules\Admin\Http\Controllers\Admin\ErpAdminController::class, 'summary']);
                Route::get('erp/batches', [\Modules\Admin\Http\Controllers\Admin\ErpAdminController::class, 'batches']);
                Route::post('erp/export', [\Modules\Admin\Http\Controllers\Admin\ErpAdminController::class, 'export']);

                // Companies
                Route::get('companies', [CompanyController::class, 'index']);
                Route::post('companies', [CompanyController::class, 'store']);
                Route::get('companies/{id}', [CompanyController::class, 'show']);
                Route::patch('companies/{id}', [CompanyController::class, 'update']);
                Route::delete('companies/{id}', [CompanyController::class, 'destroy']);
                Route::post('companies/{id}/suspend', [CompanyController::class, 'suspend']);
                Route::post('companies/{id}/activate', [CompanyController::class, 'activate']);
                Route::post('companies/{id}/upgrade', [CompanyController::class, 'upgrade']);
                Route::post('companies/{id}/admin/reset-password', [CompanyController::class, 'resetAdminPassword']);
                Route::post('companies/{id}/impersonate', [CompanyController::class, 'impersonate']);
                Route::post('companies/{id}/send-reminder', [CompanyController::class, 'sendReminder']);
                Route::get('companies/{id}/modules', [CompanyController::class, 'modules']);
                Route::patch('companies/{id}/modules', [CompanyController::class, 'updateModules']);
                Route::get('companies/{id}/usage', [CompanyController::class, 'usage']);

                // Brands / Restaurants / Branches
                Route::get('brands', [BrandController::class, 'index']);
                Route::post('brands', [BrandController::class, 'store']);
                Route::patch('brands/{id}', [BrandController::class, 'update']);
                Route::delete('brands/{id}', [BrandController::class, 'destroy']);
                Route::post('brands/{brandId}/restaurants', [RestaurantController::class, 'store']);
                Route::post('brands/{brandId}/auto-reminder', [BrandController::class, 'autoReminder']);
                Route::post('brands/{brandId}/subscription/renew', [BrandController::class, 'renewSubscription']);
                Route::post('brands/{brandId}/subscription/activate', [BrandController::class, 'activateSubscription']);
                Route::post('brands/{brandId}/owner/reset-password', [BrandController::class, 'resetOwnerPassword']);
                Route::patch('restaurants/{id}', [RestaurantController::class, 'update']);
                Route::delete('restaurants/{id}', [RestaurantController::class, 'destroy']);
                Route::post('restaurants/{restaurantId}/subscription/renew', [RestaurantController::class, 'renewSubscription']);
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
                Route::post('users/{id}/reset-password', [UserController::class, 'resetPassword']);

                // Distribution
                Route::get('distribution', [DistributionController::class, 'index']);
                Route::post('distribution/assign-restaurant', [DistributionController::class, 'assignRestaurant']);
                Route::delete('distribution/assign-restaurant', [DistributionController::class, 'unassignRestaurant']);
                Route::post('distribution/assign-modules', [DistributionController::class, 'assignModules']);
                Route::post('distribution/move-to-head', [DistributionController::class, 'moveToHead']);

                // Subscriptions
                Route::get('subscriptions', [SubscriptionController::class, 'index']);
                Route::post('subscriptions', [SubscriptionController::class, 'store']);
                Route::post('subscriptions/{id}/renew', [SubscriptionController::class, 'renew']);
                Route::post('subscriptions/{id}/change-plan', [SubscriptionController::class, 'changePlan']);
                Route::patch('subscriptions/{id}/modules', [SubscriptionController::class, 'updateModules']);
                Route::post('subscriptions/{id}/toggle-auto-reminder', [SubscriptionController::class, 'toggleAutoReminder']);
                Route::post('subscriptions/{id}/suspend', [SubscriptionController::class, 'suspend']);
                Route::post('subscriptions/{id}/activate', [SubscriptionController::class, 'activate']);

                // Brand subscription packages (WS6)
                Route::get('packages', [PackageController::class, 'index']);
                Route::post('packages', [PackageController::class, 'store']);
                Route::patch('packages/{id}', [PackageController::class, 'update']);
                Route::delete('packages/{id}', [PackageController::class, 'destroy']);

                // Permissions matrix
                Route::get('permissions', [PermissionMatrixController::class, 'index']);
                Route::put('permissions', [PermissionMatrixController::class, 'replace']);
                Route::patch('permissions/cell', [PermissionMatrixController::class, 'updateCell']);
                Route::post('permissions/clone', [PermissionMatrixController::class, 'clone']);

                // Permission matrix version history (FE completion request §2.3)
                Route::get('permissions/history', [PermissionMatrixController::class, 'history']);
                Route::get('permissions/history/{snapshotId}', [PermissionMatrixController::class, 'historyShow']);
                Route::post('permissions/history/{snapshotId}/restore', [PermissionMatrixController::class, 'restore']);

                // Audit + settings + reports
                Route::get('audit-logs/export', [AuditLogController::class, 'export']);
                Route::get('audit-logs/filters', [AuditLogController::class, 'filters']);
                Route::get('audit-logs', [AuditLogController::class, 'index']);
                Route::get('audit-logs/{id}', [AuditLogController::class, 'show']);
                Route::get('settings', [SettingsController::class, 'show']);
                Route::patch('settings', [SettingsController::class, 'update']);

                // Notification preferences + module lookup, admin-scoped (FE wiring B1/B2).
                // The shared copies live at /notifications/preferences & /lookups/modules,
                // but the admin SPA calls them under /admin and a platform admin has no
                // companyId for the /company/me/* variants — so expose them here too.
                Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
                Route::patch('notifications/preferences', [NotificationController::class, 'updatePreferences']);
                Route::get('lookups/modules', [LookupController::class, 'modules']);
                Route::get('reports/catalog', [ReportController::class, 'catalog']);
                Route::post('reports/generate', [ReportController::class, 'generate']);

                // Background-job monitoring (FE completion request §3.6)
                Route::get('jobs', [JobMonitorController::class, 'index']);
                Route::post('jobs/{id}/retry', [JobMonitorController::class, 'retry']);
                Route::post('jobs/{id}/cancel', [JobMonitorController::class, 'cancel']);

                // Excel/CSV bulk uploads (§6.1.5). The employees upload was dropped
                // per the client meeting (avoid confusion with user management).
                Route::post('brands/{brandId}/upload/{type}', [AdminUploadController::class, 'brandUpload']);
                Route::post('branches/{branchId}/upload/fixed-assets', [AdminUploadController::class, 'fixedAssets']);
                Route::get('upload/templates/{type}', [AdminUploadController::class, 'template']);
                Route::get('brands/{brandId}/upload-status', [AdminUploadController::class, 'status']);

                // Doc-conformance: plural 'uploads' aliases (FE wiring §1.6)
                Route::post('brands/{brandId}/uploads/{type}', [AdminUploadController::class, 'brandUpload']);
                Route::get('uploads/templates/{type}', [AdminUploadController::class, 'template']);

                // Report distribution (FE wiring §1.7)
                Route::get('reports/periods', [ReportController::class, 'periods']);
                Route::post('reports/{reportKey}/send', [ReportController::class, 'send']);
                Route::post('reports/{reportKey}/upload', [ReportController::class, 'uploadReport']);
                Route::get('reports/{reportKey}/preview', [ReportController::class, 'preview']);
                Route::get('reports/{reportKey}/status', [ReportController::class, 'status']);

                // Accountant assignment & per-restaurant modules (FE wiring §1.8, §1.9)
                Route::patch('accountants/{accId}/assignments', [DistributionController::class, 'assignments']);
                Route::put('accountants/{accId}/restaurants/{restaurant}/modules', [DistributionController::class, 'restaurantModules']);
            });

        // ---- Shared (auth + tenant) ----
        Route::middleware('asab.tenant')->group(function () {

            // Approval pipeline (§5)
            Route::get('operations', [OperationController::class, 'index']);
            // Bulk export (head/accountant) — must precede operations/{id} so "export" isn't captured as an id.
            Route::get('operations/export', [CompanyExportController::class, 'operationsExport'])->middleware('asab.role:accountant,head');
            Route::post('operations/bulk-approve', [OperationController::class, 'bulkApprove'])->middleware(['asab.role:accountant,head', 'asab.idempotency']);
            // HEAD-2.1/2.4 group actions (head-only). Declared before operations/{id}
            // so the literal segments cannot be captured as an id.
            Route::post('operations/bulk-final-approve', [OperationController::class, 'bulkFinalApprove'])->middleware(['asab.role:head', 'asab.idempotency']);
            Route::post('operations/bulk-return', [OperationController::class, 'bulkReturn'])->middleware(['asab.role:head', 'asab.idempotency']);
            Route::get('operations/{id}', [OperationController::class, 'show']);
            Route::get('operations/{id}/audit-trail', [OperationController::class, 'auditTrail']);
            // ACC-1.4 attachments panel (POS report / bank statement / aggregator sheets).
            Route::get('operations/{id}/attachments', [OperationController::class, 'attachments']);
            Route::post('operations/{id}/approve', [OperationController::class, 'approve'])->middleware('asab.role:accountant,head');
            Route::post('operations/{id}/reject', [OperationController::class, 'reject'])->middleware('asab.role:accountant,head');
            // Conditional approval is the isConditional flag on final-approve (FE completion request §1.6).
            Route::post('operations/{id}/final-approve', [OperationController::class, 'finalApprove'])->middleware(['asab.role:head', 'asab.idempotency']);
            // HEAD-2.1 «إرجاع للمراجعة» — head returns an approved op to the accountant.
            Route::post('operations/{id}/return-for-review', [OperationController::class, 'returnForReview'])->middleware(['asab.role:head', 'asab.idempotency']);
            Route::post('operations/{id}/correction', [OperationController::class, 'correction'])->middleware('asab.role:accountant,head');
            // «طلب توضيح» (SRS ACC-0.5) — non-terminal: asks the submitter for
            // information without moving the operation off its stage.
            Route::post('operations/{id}/request-clarification', [OperationController::class, 'requestClarification'])->middleware('asab.role:accountant,head');
            // ACC-3.4 «توثيق» — accountant documents a purchase order before the head.
            Route::post('operations/{id}/document', [OperationController::class, 'document'])->middleware('asab.role:accountant,head');
            // ACC-3.4 accountant edit of one purchase line (recomputes amount + 3-way match).
            Route::patch('operations/{id}/purchase-lines/{rowId}', [AccountantController::class, 'purchaseLineUpdate'])->middleware('asab.role:accountant,head');
            // ACC-3 «المرتجعات» read surface (accountant/head).
            Route::get('purchases/returns', [PurchaseReturnController::class, 'index'])->middleware('asab.role:accountant,head');

            // ERP (§5 / §7.4)
            Route::post('erp/batches', [HeadController::class, 'erpCreateBatch'])->middleware(['asab.role:head', 'asab.idempotency']);
            // T10.6 retry a failed batch (head or admin).
            Route::post('erp/batches/{batchId}/retry', [ErpController::class, 'retry'])->middleware(['asab.role:head,admin', 'asab.idempotency']);
            // T10.8: batch status/downloads restricted to head+admin (were open to any role).
            Route::middleware('asab.role:head,admin')->group(function () {
                Route::get('erp/batches/{batchId}/status', [ErpController::class, 'status']);
                Route::get('erp/batches/{batchId}/download.json', [ErpController::class, 'downloadJson']);
                Route::get('erp/batches/{batchId}/download.csv', [ErpController::class, 'downloadCsv']);
                Route::get('erp/batches/{batchId}/download.xlsx', [ErpController::class, 'downloadXlsx']);
            });

            // Cross-cutting (§7)
            Route::get('pipeline/overview', [PipelineController::class, 'overview']);
            // §5.2c per-branch/day rollup state machine (main-dashboard state chips).
            Route::get('pipeline/daily-rollup', [PipelineController::class, 'dailyRollup']);
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
            Route::get('lookups/exceptions', [LookupController::class, 'exceptions']);
            // Pipeline enum catalogues (SRS §5): the reject modal's fixed reason
            // list and every status/stage/origin/match/rollup label.
            Route::get('lookups/rejection-reasons', [LookupController::class, 'rejectionReasons']);
            Route::get('lookups/operation-enums', [LookupController::class, 'operationEnums']);
            // Fixed-assets + expenses vocabulary (SRS §4.2 / ACC-2).
            Route::get('lookups/asset-enums', [LookupController::class, 'assetEnums']);
            // Purchases vocabulary (ACC-3): order source, line match, return status.
            Route::get('lookups/purchase-enums', [LookupController::class, 'purchaseEnums']);

            Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
            Route::patch('notifications/preferences', [NotificationController::class, 'updatePreferences']);
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

            // Custom report builder (FE completion request §2.4)
            Route::middleware('asab.role:accountant,head,company-admin')->prefix('reports/builder')->group(function () {
                Route::get('fields', [ReportBuilderController::class, 'fields']);
                Route::post('preview', [ReportBuilderController::class, 'preview']);
                Route::post('save', [ReportBuilderController::class, 'save']);
            });

            // ---- Head Accountant (رئيس الحسابات; §6.2) ----
            Route::middleware('asab.role:head')->prefix('head')->group(function () {
                Route::get('dashboard', [HeadController::class, 'dashboard']);
                Route::get('operations/pending', [HeadController::class, 'pending']);
                Route::get('operations/final-approved', [HeadController::class, 'finalApproved']);
                Route::get('operations/rejected', [HeadController::class, 'rejected']);
                Route::get('accountants/performance', [HeadController::class, 'accountantsPerformance']);
                Route::get('movements/recent', [HeadController::class, 'movementsRecent']);
                Route::get('erp/preflight', [HeadController::class, 'erpPreflight']);
                Route::get('erp/eligible-operations', [HeadController::class, 'erpEligible']);
                Route::get('erp/batches', [HeadController::class, 'erpBatches']);
                Route::get('reports/internal', [HeadController::class, 'reportsInternal']);
                Route::get('reports/owner', [HeadController::class, 'reportsOwner']);

                // Personal reminders for the platform head surface (B-H6). The
                // company surface already exposes these under /company/me/head/*;
                // the platform head SPA calls them under /head/* — so mirror here.
                Route::get('reminders', [PersonalReminderController::class, 'index']);
                Route::post('reminders', [PersonalReminderController::class, 'store']);
                Route::post('reminders/mark-all-done', [PersonalReminderController::class, 'markAllDone']);
                Route::patch('reminders/{id}', [PersonalReminderController::class, 'update']);
                Route::delete('reminders/{id}', [PersonalReminderController::class, 'destroy']);
            });

            // ---- Accountant (المحاسب; §6.3) ----
            Route::middleware('asab.role:accountant,head')->prefix('accountant')->group(function () {
                Route::get('dashboard', [AccountantController::class, 'dashboard']);
                Route::get('dashboard/activity-heatmap', [AccountantController::class, 'activityHeatmap']);
                Route::get('operations', [AccountantController::class, 'operations']);
                // ACC-1.1 / ACC-1.2 — sales KPI cards and the day-pill completeness banner.
                Route::get('sales/kpis', [AccountantCompanyController::class, 'salesKpis']);
                Route::get('sales/day-completeness', [AccountantCompanyController::class, 'salesDayCompleteness']);
                Route::patch('operations/{id}/reconciliation', [AccountantController::class, 'reconciliation']);
                // ACC-2.1 — expenses KPI cards + the matched/mismatch/missing split.
                Route::get('expenses/kpis', [AccountantController::class, 'expenseKpis']);
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
                Route::get('inventory/branches/{branchId}/daily-reconciliation', [InventoryController::class, 'dailyReconciliation']);
                Route::post('inventory/branches/{branchId}/daily-variance-allocation', [InventoryController::class, 'saveDailyVarianceAllocation']);
                // ACC-4.6 accountant-surface Excel/CSV exports (scoped to assigned branches).
                Route::get('inventory/export', [CompanyExportController::class, 'inventoryExport']);

                // Waste (§6.3.7)
                Route::get('waste', [WasteController::class, 'index']);
                Route::get('waste/export', [CompanyExportController::class, 'waste']);
                Route::patch('waste/{entryId}/products/{productIdx}', [WasteController::class, 'classifyProduct']);
                Route::put('waste/{entryId}/products/{productIdx}/allocations', [WasteController::class, 'allocations']);
                Route::post('waste/{entryId}/approve', [WasteController::class, 'approve']);
                Route::post('waste/{entryId}/reject', [WasteController::class, 'reject']);
                Route::post('waste/bulk-approve', [WasteController::class, 'bulkApprove']);

                // Shifts (§6.3.9)
                Route::get('shifts/live', [ShiftController::class, 'live']);
                Route::get('shifts/history', [ShiftController::class, 'history']);
                Route::post('shifts/{id}/close', [ShiftController::class, 'close']);
                // ACC-6.4 accountant's split of the cash gap before head approval.
                Route::post('shifts/{id}/variance-allocations', [ShiftController::class, 'varianceAllocations']);

                // Employees (§6.3.10)
                Route::get('employees', [EmployeeController::class, 'index']);
                Route::get('employees/{id}/statement', [EmployeeController::class, 'statement']);
                Route::post('employees/{id}/movements', [EmployeeController::class, 'addMovement']);
                // ACC-7.3 «تسوية الرصيد».
                Route::post('employees/{id}/settle-balance', [EmployeeController::class, 'settleBalance']);

                // Cash custody (§6.3.11)
                Route::get('cash-custody', [CashCustodyController::class, 'index']);
                Route::post('cash-custody/{id}/settlement-request', [CashCustodyController::class, 'settlementRequest']);
                Route::post('cash-custody/{id}/transactions', [CashCustodyController::class, 'addTransaction']);
            });

            // Reminders (§6.3.12) — accountant + head
            Route::middleware('asab.role:accountant,head')->group(function () {
                Route::get('reminders', [AccountantController::class, 'reminders']);
                Route::post('reminders/broadcast', [ReminderController::class, 'broadcast']);
                Route::post('reminders/{id}/send', [ReminderController::class, 'send']);
                Route::post('reminders/bulk-send', [ReminderController::class, 'bulkSend']);
                Route::post('reminders/{id}/respond', [ReminderController::class, 'respond']);
                Route::get('reminders/rules', [ReminderController::class, 'rules']);
                Route::post('reminders/rules', [ReminderController::class, 'storeRule']);
                Route::patch('reminders/rules/{id}', [ReminderController::class, 'updateRule']);
                Route::delete('reminders/rules/{id}', [ReminderController::class, 'deleteRule']);
                // Doc-conformance aliases (FE wiring §2.11)
                Route::post('accountant/reminder-rules', [ReminderController::class, 'storeRule']);
                Route::patch('accountant/reminder-rules/{id}', [ReminderController::class, 'updateRule']);
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

                // Mobile purchase-order pipeline (meeting flow: app request → dashboard
                // decision). Literal segments before the {id} route.
                Route::get('purchase-orders/approved-by-me', [ProcurementPurchaseOrderController::class, 'approvedByMe']);
                Route::post('purchase-orders/bulk-approve', [ProcurementPurchaseOrderController::class, 'bulkApprove']);
                Route::get('purchase-orders/grouped', [ProcurementPurchaseOrderController::class, 'grouped']);
                Route::post('purchase-orders/grouped/send', [ProcurementPurchaseOrderController::class, 'sendGroup']);
                Route::get('purchase-orders/sent', [ProcurementPurchaseOrderController::class, 'sent']);
                Route::get('purchase-orders/groups/{groupId}', [ProcurementPurchaseOrderController::class, 'groupShow']);
                Route::get('purchase-orders', [ProcurementPurchaseOrderController::class, 'index']);
                Route::get('purchase-orders/{id}', [ProcurementPurchaseOrderController::class, 'show']);
                Route::post('purchase-orders/{id}/approve', [ProcurementPurchaseOrderController::class, 'approve']);
                Route::post('purchase-orders/{id}/partial-approve', [ProcurementPurchaseOrderController::class, 'partialApprove']);
                Route::post('purchase-orders/{id}/reject', [ProcurementPurchaseOrderController::class, 'reject']);
            });

            // ---- Supplier (المورد; §6.6) ----
            // Namespaced under asab/supplier: the legacy Supplier module already owns
            // /api/v1/supplier/* (mobile portal, different auth guard). Keeping both.
            // Hidden for now per the client meeting (main features first); flip
            // FEATURE_ASAB_SUPPLIER_PORTAL to re-enable.
            Route::middleware('asab.role:supplier')->prefix('asab/supplier')->group(function () {
                if (! config('features.asab_supplier_portal')) {
                    return;
                }
                Route::get('overview', [SupplierController::class, 'overview']);
                Route::get('orders/export', [SupplierController::class, 'ordersExport']);
                Route::get('orders', [SupplierController::class, 'orders']);
                Route::post('orders/{id}/accept', [SupplierController::class, 'accept']);
                Route::post('orders/{id}/reject', [SupplierController::class, 'reject']);
                Route::post('orders/{id}/mark-delivered', [SupplierController::class, 'markDelivered']);
                Route::get('items/export', [SupplierController::class, 'itemsExport']);
                Route::get('items', [SupplierController::class, 'items']);
                Route::post('items', [SupplierController::class, 'storeItem']);
                Route::patch('items/{id}', [SupplierController::class, 'updateItem']);
                Route::post('items/{id}/toggle-active', [SupplierController::class, 'toggleItem']);
                Route::delete('items/{id}', [SupplierController::class, 'destroyItem']);
                Route::get('reports', [SupplierController::class, 'reports']);
            });

            /*
             | ---- Company Dashboard (بوابة الشركات; COMPANY_DASHBOARD_API_SPEC.md) ----
             | B2B portal scoped to one company. company-admin manages subscription,
             | users, brands, modules, billing, settings; support open to any role.
             */
            Route::get('plans', [CompanySubscriptionController::class, 'plans'])
                ->middleware(['asab.tenant', 'asab.role:company-admin,head']);

            Route::middleware(['asab.tenant', 'asab.role:company-admin', 'asab.idempotency', 'asab.audit'])
                ->prefix('company')
                ->group(function () {
                    Route::post('onboard', [OnboardController::class, 'onboard']);

                    // Invitations (§4.2)
                    Route::get('invitations', [CompanyUserController::class, 'invitations']);
                    Route::post('invitations', [CompanyUserController::class, 'invite']);
                    Route::post('invitations/{id}/revoke', [CompanyUserController::class, 'revokeInvitation']);

                    // ca-dashboard (§5.1.1)
                    Route::get('me/dashboard/brand-performance', [CompanyDashboardController::class, 'brandPerformance']);
                    Route::get('me/dashboard', [CompanyDashboardController::class, 'index']);

                    // ca-subscription (§5.1.2)
                    Route::get('me/subscription', [CompanySubscriptionController::class, 'show']);
                    Route::post('me/subscription/upgrade', [CompanySubscriptionController::class, 'upgrade']);
                    Route::post('me/subscription/downgrade', [CompanySubscriptionController::class, 'downgrade']);
                    Route::post('me/subscription/cancel', [CompanySubscriptionController::class, 'cancel']);
                    Route::post('me/subscription/reactivate', [CompanySubscriptionController::class, 'reactivate']);
                    Route::post('me/subscription/contact-sales', [CompanySubscriptionController::class, 'contactSales']);
                    Route::post('me/subscription/billing-cycle', [CompanySubscriptionController::class, 'billingCycle']);

                    // ca-users (§5.1.3)
                    Route::get('me/users', [CompanyUserController::class, 'index']);
                    Route::post('me/users', [CompanyUserController::class, 'invite']);
                    Route::patch('me/users/{id}', [CompanyUserController::class, 'update']);
                    Route::post('me/users/{id}/toggle-status', [CompanyUserController::class, 'toggleStatus']);
                    Route::delete('me/users/{id}', [CompanyUserController::class, 'destroy']);
                    Route::post('me/users/{id}/resend-invite', [CompanyUserController::class, 'resendInvite']);

                    // ca-branches (§5.1.4)
                    Route::get('me/brands', [CompanyOrgController::class, 'tree']);
                    Route::post('me/brands', [CompanyOrgController::class, 'storeBrand']);
                    Route::patch('me/brands/{id}', [CompanyOrgController::class, 'updateBrand']);
                    Route::delete('me/brands/{id}', [CompanyOrgController::class, 'destroyBrand']);
                    Route::post('me/restaurants', [CompanyOrgController::class, 'storeRestaurant']);
                    Route::patch('me/restaurants/{id}', [CompanyOrgController::class, 'updateRestaurant']);
                    Route::delete('me/restaurants/{id}', [CompanyOrgController::class, 'destroyRestaurant']);
                    Route::post('me/branches', [CompanyOrgController::class, 'storeBranch']);
                    Route::patch('me/branches/{id}', [CompanyOrgController::class, 'updateBranch']);
                    Route::delete('me/branches/{id}', [CompanyOrgController::class, 'destroyBranch']);
                    Route::post('me/branches/{id}/transfer-manager', [CompanyOrgController::class, 'transferManager']);

                    // ca-modules (§5.1.5)
                    Route::get('me/modules', [CompanyModuleController::class, 'index']);
                    Route::patch('me/modules/{moduleKey}', [CompanyModuleController::class, 'toggle']);

                    // ca-billing (§5.1.6)
                    Route::get('me/billing/summary', [CompanyBillingController::class, 'summary']);
                    Route::get('me/billing/invoices/export', [CompanyBillingController::class, 'export']);
                    Route::get('me/exports/{jobId}/download', [CompanyExportController::class, 'download']);
                    Route::get('me/billing/invoices', [CompanyBillingController::class, 'invoices']);
                    Route::get('me/billing/invoices/{id}', [CompanyBillingController::class, 'show']);
                    Route::get('me/billing/invoices/{id}/pdf', [CompanyBillingController::class, 'pdf']);
                    Route::post('me/billing/invoices/{id}/pay', [CompanyBillingController::class, 'pay']);
                    Route::get('me/billing/payment-methods', [CompanyBillingController::class, 'paymentMethods']);
                    Route::post('me/billing/payment-methods', [CompanyBillingController::class, 'addPaymentMethod']);
                    Route::post('me/billing/payment-methods/{id}/set-default', [CompanyBillingController::class, 'setDefaultPaymentMethod']);
                    Route::delete('me/billing/payment-methods/{id}', [CompanyBillingController::class, 'deletePaymentMethod']);
                    Route::get('me/billing/address', [CompanyBillingController::class, 'address']);
                    Route::put('me/billing/address', [CompanyBillingController::class, 'updateAddress']);

                    // ca-settings (§5.1.7)
                    Route::get('me/settings', [CompanySettingsController::class, 'show']);
                    Route::put('me/settings', [CompanySettingsController::class, 'update']);
                    // Doc-conformance: PATCH alias for company settings (FE wiring §7.1)
                    Route::patch('me/settings', [CompanySettingsController::class, 'update']);
                    Route::post('me/settings/logo', [CompanySettingsController::class, 'uploadLogo']);
                    Route::patch('me/preferences', [CompanySettingsController::class, 'updatePreferences']);

                    // API keys (FE completion request §3.3)
                    Route::get('me/api-keys', [ApiKeyController::class, 'index']);
                    Route::post('me/api-keys', [ApiKeyController::class, 'store']);
                    Route::delete('me/api-keys/{id}', [ApiKeyController::class, 'destroy']);

                    // SSO config (FE completion request §3.2, Enterprise)
                    Route::get('me/sso', [CompanySsoController::class, 'show']);
                    Route::put('me/sso', [CompanySsoController::class, 'update']);
                    Route::delete('me/sso', [CompanySsoController::class, 'destroy']);

                    // Outbound webhooks (FE completion request §3.5)
                    Route::get('me/webhooks', [TenantWebhookController::class, 'index']);
                    Route::post('me/webhooks', [TenantWebhookController::class, 'store']);
                    Route::get('me/webhooks/{id}/deliveries', [TenantWebhookController::class, 'deliveries']);
                    Route::patch('me/webhooks/{id}', [TenantWebhookController::class, 'update']);
                    Route::delete('me/webhooks/{id}', [TenantWebhookController::class, 'destroy']);
                    Route::post('me/webhooks/{id}/test', [TenantWebhookController::class, 'test']);
                });

            // ca-support (§5.1.8) — any company role may open/view their tickets
            Route::middleware(['asab.tenant', 'asab.role:company-admin,head,accountant,branch,procurement', 'asab.idempotency'])
                ->prefix('company/me/support')
                ->group(function () {
                    Route::get('channels', [CompanySupportController::class, 'channels']);

                    // Live chat (FE completion request §2.1)
                    Route::post('chat/start', [SupportChatController::class, 'start']);
                    Route::get('chat/{sessionId}', [SupportChatController::class, 'show']);
                    Route::post('chat/{sessionId}/message', [SupportChatController::class, 'message']);
                    Route::post('chat/{sessionId}/close', [SupportChatController::class, 'close']);

                    Route::get('tickets', [CompanySupportController::class, 'index']);
                    Route::post('tickets', [CompanySupportController::class, 'store']);
                    Route::get('tickets/{id}', [CompanySupportController::class, 'show']);
                    Route::post('tickets/{id}/reply', [CompanySupportController::class, 'reply']);
                    Route::post('tickets/{id}/close', [CompanySupportController::class, 'close']);
                    Route::post('tickets/{id}/attachments', [CompanySupportController::class, 'addAttachment']);
                });

            /*
             | ---- Company Dashboard role surfaces (§5.2–§5.5, §7) ----
             | All under /company/me/*, tenant-scoped. Reuse existing controllers
             | where the logic is identical; new Company controllers for new logic.
             */
            Route::prefix('company/me')->middleware(['asab.tenant', 'asab.idempotency', 'asab.audit'])->group(function () {

                // Head (§5.2)
                Route::middleware('asab.role:head')->group(function () {
                    Route::get('head/dashboard', [HeadCompanyController::class, 'dashboard']);
                    Route::get('head/accountants/performance', [HeadCompanyController::class, 'accountantsPerformance']);
                    Route::get('head/movements/recent', [HeadCompanyController::class, 'movementsRecent']);
                    Route::get('head/reminders', [PersonalReminderController::class, 'index']);
                    Route::patch('head/reminders/{id}', [PersonalReminderController::class, 'update']);
                    Route::post('head/reminders/mark-all-done', [PersonalReminderController::class, 'markAllDone']);
                    Route::post('operations/{id}/post-to-erp', [HeadCompanyController::class, 'postToErp']);
                    // Filtered ERP batch preview (FE wiring §3.1)
                    Route::get('erp/preview', [HeadCompanyController::class, 'erpPreview']);
                    // HEAD-2 final-approval on the portal (single + group) + «إرجاع للمراجعة».
                    Route::post('operations/{id}/final-approve', [OperationController::class, 'finalApprove']);
                    Route::post('operations/bulk-final-approve', [OperationController::class, 'bulkFinalApprove']);
                    Route::post('operations/{id}/return-for-review', [OperationController::class, 'returnForReview']);
                    Route::post('operations/bulk-return', [OperationController::class, 'bulkReturn']);
                });

                // Accountant (§5.3)
                Route::middleware('asab.role:accountant')->group(function () {
                    Route::get('accountant/dashboard', [AccountantCompanyController::class, 'dashboard']);
                    // ACC-1.1 sales KPI cards + ACC-1.2 day pills («n مطلوبة — m مكتملة · k ناقصة»).
                    Route::get('sales/kpis', [AccountantCompanyController::class, 'salesKpis']);
                    Route::get('sales/day-completeness', [AccountantCompanyController::class, 'salesDayCompleteness']);
                    Route::get('operations/{id}/attachments', [OperationController::class, 'attachments']);
                    Route::get('accountant/reminders/export', [CompanyExportController::class, 'remindersExport']);
                    Route::get('accountant/reminders', [PersonalReminderController::class, 'index']);
                    Route::post('accountant/reminders', [PersonalReminderController::class, 'store']);
                    Route::patch('accountant/reminders/{id}', [PersonalReminderController::class, 'update']);
                    Route::delete('accountant/reminders/{id}', [PersonalReminderController::class, 'destroy']);

                    Route::get('operations/export', [CompanyExportController::class, 'operationsExport']);
                    Route::get('operations', [OperationController::class, 'index']);
                    // Declared after the literal `operations/export` so it cannot shadow it.
                    Route::get('operations/{id}', [OperationController::class, 'show']);
                    Route::post('operations/bulk-approve', [OperationController::class, 'bulkApprove']);
                    Route::post('operations/{id}/approve', [OperationController::class, 'approve']);
                    Route::patch('operations/{id}/sales-details', [AccountantController::class, 'reconciliation']);
                    Route::post('operations/{id}/sales-variance/assign', [AccountantCompanyController::class, 'salesVarianceAssign']);
                    // Doc-conformance (FE wiring §2.5, §2.6, §2.7)
                    Route::patch('operations/{id}/reconciliation', [AccountantController::class, 'reconciliation']);
                    Route::post('operations/{id}/variance-allocations', [AccountantCompanyController::class, 'salesVarianceAssign']);
                    Route::patch('operations/{id}/sales-lines/{rowId}', [AccountantController::class, 'salesLineUpdate']);
                    // ACC-3.4 purchases: توثيق + line edit (company surface).
                    Route::post('operations/{id}/document', [OperationController::class, 'document']);
                    Route::patch('operations/{id}/purchase-lines/{rowId}', [AccountantController::class, 'purchaseLineUpdate']);
                    Route::get('purchases/returns', [PurchaseReturnController::class, 'index']);
                    Route::post('operations/{id}/notes', [AccountantController::class, 'addNote']);
                    Route::get('operations/{id}/export', [CompanyExportController::class, 'operation']);
                    Route::get('branches/{branchId}/employees/lookup', [AccountantCompanyController::class, 'employeeLookup']);

                    // ACC-2.1 expenses KPI cards + the invoice-match split.
                    Route::get('expenses/kpis', [AccountantCompanyController::class, 'expenseKpis']);
                    Route::post('expense-invoices/{invoiceId}/verify', [AccountantCompanyController::class, 'verifyExpense']);
                    Route::delete('expense-invoices/{invoiceId}/verify', [AccountantCompanyController::class, 'unverifyExpense']);
                    Route::get('expense-invoices/{invoiceId}/attachments', [AccountantCompanyController::class, 'expenseAttachments']);
                    // ACC-2.3 review modal — what the accountant read off the document.
                    Route::patch('expense-invoices/{invoiceId}/invoices/{invoiceIndex}', [AccountantCompanyController::class, 'reviewInvoice']);
                    Route::post('expense-invoices/{invoiceId}/convert-to-asset-draft', [AccountantController::class, 'convertToAsset']);
                    Route::post('asset-drafts/{draftId}/confirm', [AssetController::class, 'confirmDraft']);
                    Route::post('asset-drafts/{draftId}/discard', [AssetController::class, 'discardDraft']);

                    Route::get('inventory/export', [CompanyExportController::class, 'inventoryExport']);
                    Route::get('inventory/branches', [InventoryController::class, 'index']);
                    Route::post('inventory/branches/{branchId}/flag', [InventoryController::class, 'flagBranch']);
                    Route::post('inventory/branches/{branchId}/flag-items', [InventoryController::class, 'flagItems']);
                    Route::post('inventory/branches/{branchId}/send-notification', [AccountantCompanyController::class, 'inventorySendNotification']);
                    Route::post('inventory/branches/{branchId}/mark-confirmed', [AccountantCompanyController::class, 'inventoryMarkConfirmed']);
                    Route::get('inventory/items', [InventoryController::class, 'catalog']);
                    Route::get('branches/{branchId}/inventory-list', [InventoryController::class, 'dailyList']);
                    Route::put('branches/{branchId}/inventory-list', [InventoryController::class, 'saveDailyList']);
                    // Doc-conformance (FE wiring §2.8, §2.9)
                    Route::put('inventory/catalog', [InventoryController::class, 'storeCatalog']);
                    Route::post('inventory/branches/{branchId}/confirm', [AccountantCompanyController::class, 'inventoryMarkConfirmed']);
                    Route::post('inventory/branches/{branchId}/flagged-items', [InventoryController::class, 'flagItems']);

                    Route::get('waste/export', [CompanyExportController::class, 'waste']);
                    Route::get('waste', [WasteController::class, 'index']);
                    Route::post('waste/bulk-approve', [WasteController::class, 'bulkApprove']);
                    Route::patch('waste/{id}/products/{idx}', [WasteController::class, 'classifyProduct']);
                    Route::put('waste/{id}/products/{idx}/allocations', [WasteController::class, 'allocations']);
                    Route::post('waste/{id}/approve', [WasteController::class, 'approve']);
                    Route::post('waste/{id}/reject', [WasteController::class, 'reject']);

                    Route::get('assets/export', [CompanyExportController::class, 'assetsExport']);
                    Route::get('assets', [AssetController::class, 'index']);
                    Route::post('assets', [AssetController::class, 'store']);
                    Route::post('assets/import', [AccountantCompanyController::class, 'importAssets']);
                    Route::patch('assets/{id}', [AccountantCompanyController::class, 'updateAsset']);

                    Route::get('shifts/configs', [AccountantCompanyController::class, 'shiftConfigs']);
                    Route::get('shifts/export', [CompanyExportController::class, 'shifts']);
                    Route::get('shifts', [ShiftController::class, 'index']);
                    Route::post('shifts/{id}/close', [ShiftController::class, 'close']);
                    Route::post('shifts/{id}/variance-allocations', [ShiftController::class, 'varianceAllocations']);
                    Route::put('brands/{brandId}/shift-config', [AccountantCompanyController::class, 'saveShiftConfig']);

                    Route::get('employees/payroll/export', [CompanyExportController::class, 'payroll']);
                    Route::get('employees', [EmployeeController::class, 'index']);
                    Route::get('employees/{id}/movements', [EmployeeController::class, 'statement']);
                    Route::get('employees/{id}/statement/export', [CompanyExportController::class, 'employeeStatement']);
                    // ACC-7.3 «تسوية الرصيد».
                    Route::post('employees/{id}/settle-balance', [EmployeeController::class, 'settleBalance']);
                });

                // Cash custody (§5.3 ACC-8 + §5.2 HEAD-4) — the head OWNS تعزيز العهدة,
                // so this block admits both accountant and head on the company surface.
                Route::middleware('asab.role:accountant,head')->group(function () {
                    Route::get('cash-custody/export', [CompanyExportController::class, 'cashCustody']);
                    Route::get('cash-custody', [CashCustodyController::class, 'index']);
                    Route::get('cash-custody/{id}/transactions', [AccountantCompanyController::class, 'cashTransactions']);
                    Route::get('cash-custody/{id}/transactions/export', [CompanyExportController::class, 'custodyLedger']);
                    // HEAD-4 replenish / generic txn (source=treasury → تعزيز عهدة من الخزينة).
                    Route::post('cash-custody/{id}/transactions', [CashCustodyController::class, 'addTransaction']);
                    Route::post('cash-custody/{id}/settlement-request', [CashCustodyController::class, 'settlementRequest']);
                    Route::post('cash-custody/{id}/transactions/{txnId}/approve', [AccountantCompanyController::class, 'approveTransaction']);
                    Route::post('cash-custody/{id}/transactions/{txnId}/reject', [AccountantCompanyController::class, 'rejectTransaction']);
                    Route::post('cash-custody/{id}/settle', [AccountantCompanyController::class, 'settleCustody']);
                });

                // Branch Manager (§5.4)
                Route::middleware('asab.role:branch')->prefix('branch')->group(function () {
                    Route::get('overview', [BranchDashboardController::class, 'overview']);
                    Route::post('upload/sign-attachment', [UploadController::class, 'presignedUrl']);
                    Route::post('upload', [BranchCompanyController::class, 'upload']);
                    Route::get('employees', [BranchDashboardController::class, 'employees']);
                    // Doc-conformance: branch manager adds an employee (FE wiring §4.3)
                    Route::post('employees', [BranchCompanyController::class, 'storeEmployee']);
                    Route::get('items', [BranchDashboardController::class, 'items']);
                    Route::post('items/count', [BranchCompanyController::class, 'itemsCount']);
                    Route::get('purchase-requests', [BranchCompanyController::class, 'purchaseRequests']);
                    Route::post('purchase-requests', [BranchCompanyController::class, 'storePurchaseRequest']);
                    Route::get('suppliers', [BranchDashboardController::class, 'suppliers']);
                    Route::post('suppliers/request-new', [BranchCompanyController::class, 'requestNewSupplier']);
                    Route::get('shifts/active', [BranchCompanyController::class, 'activeShift']);
                    // Shift timings are set by the admin/accountant surfaces only; the
                    // branch-manager role is read-only on them (client requirement §6.4).
                    Route::post('shifts/open', [BranchCompanyController::class, 'openShift']);
                    Route::post('shifts/{id}/close', [ShiftController::class, 'close']);
                    Route::get('settings', [BranchDashboardController::class, 'settings']);
                    Route::put('settings', [BranchDashboardController::class, 'updateSettings']);
                    // Doc-conformance: PATCH alias for settings (FE wiring §4.2)
                    Route::patch('settings', [BranchDashboardController::class, 'updateSettings']);
                });

                // Procurement (§5.5)
                Route::middleware('asab.role:procurement')->prefix('procurement')->group(function () {
                    Route::get('overview', [ProcurementController::class, 'overview']);
                    Route::get('orders/grouped', [ProcurementCompanyController::class, 'grouped']);
                    Route::get('orders/sent', [ProcurementCompanyController::class, 'sent']);
                    Route::get('orders', [ProcurementController::class, 'orders']);
                    Route::post('orders', [ProcurementCompanyController::class, 'storeOrder']);
                    Route::post('orders/grouped/{groupId}/send', [ProcurementController::class, 'send']);
                    // Doc-conformance (FE wiring §5.3d, §5.3c, §5.4) — literal 'orders/approve' before 'orders/{id}/...'
                    Route::post('orders/approve', [ProcurementController::class, 'bulkApprove']);
                    Route::post('orders/{id}/partial-reject', [ProcurementController::class, 'partialReject']);
                    Route::post('grouped/{groupId}/send', [ProcurementController::class, 'send']);
                    Route::post('orders/{id}/approve', [ProcurementController::class, 'approve']);
                    Route::post('orders/{id}/reject', [ProcurementController::class, 'reject']);
                    Route::patch('orders/{id}', [ProcurementCompanyController::class, 'updateOrder']);
                    Route::delete('orders/{id}', [ProcurementCompanyController::class, 'destroyOrder']);
                    // Branch «طلب مورد جديد» review/approval (T12.6).
                    Route::get('supplier-requests', [ProcurementCompanyController::class, 'supplierRequests']);
                    Route::post('supplier-requests/{id}/approve', [ProcurementCompanyController::class, 'approveSupplierRequest']);
                    Route::get('items/export', [CompanyExportController::class, 'procurementItemsExport']);
                    Route::get('items/{id}/price-history', [ProcurementCompanyController::class, 'priceHistory']);
                    Route::get('items', [ProcurementController::class, 'items']);
                    Route::post('items', [ProcurementCompanyController::class, 'storeItem']);
                    Route::patch('items/{id}', [ProcurementCompanyController::class, 'updateItem']);
                    Route::delete('items/{id}', [ProcurementCompanyController::class, 'destroyItem']);

                    // Mobile purchase-order pipeline (same bridge as the platform surface).
                    Route::get('purchase-orders/approved-by-me', [ProcurementPurchaseOrderController::class, 'approvedByMe']);
                    Route::post('purchase-orders/bulk-approve', [ProcurementPurchaseOrderController::class, 'bulkApprove']);
                    Route::get('purchase-orders/grouped', [ProcurementPurchaseOrderController::class, 'grouped']);
                    Route::post('purchase-orders/grouped/send', [ProcurementPurchaseOrderController::class, 'sendGroup']);
                    Route::get('purchase-orders/sent', [ProcurementPurchaseOrderController::class, 'sent']);
                    Route::get('purchase-orders/groups/{groupId}', [ProcurementPurchaseOrderController::class, 'groupShow']);
                    Route::get('purchase-orders', [ProcurementPurchaseOrderController::class, 'index']);
                    Route::get('purchase-orders/{id}', [ProcurementPurchaseOrderController::class, 'show']);
                    Route::post('purchase-orders/{id}/approve', [ProcurementPurchaseOrderController::class, 'approve']);
                    Route::post('purchase-orders/{id}/partial-approve', [ProcurementPurchaseOrderController::class, 'partialApprove']);
                    Route::post('purchase-orders/{id}/reject', [ProcurementPurchaseOrderController::class, 'reject']);
                });

                // Suppliers — read for all roles; write for procurement/company-admin; rate for procurement/branch
                Route::middleware('asab.role:company-admin,head,accountant,branch,procurement')->group(function () {
                    Route::get('suppliers/export', [CompanyExportController::class, 'suppliersExport']);
                    Route::get('suppliers', [ProcurementController::class, 'suppliers']);
                    // Alias: the procurement SPA naturally calls it under its own prefix.
                    Route::get('procurement/suppliers', [ProcurementController::class, 'suppliers']);
                    Route::get('procurement/suppliers/export', [CompanyExportController::class, 'suppliersExport']);
                });
                Route::middleware('asab.role:procurement,company-admin')->group(function () {
                    Route::post('suppliers', [ProcurementCompanyController::class, 'storeSupplier']);
                    Route::patch('suppliers/{id}', [ProcurementCompanyController::class, 'updateSupplier']);
                    Route::post('suppliers/{id}/toggle-active', [ProcurementCompanyController::class, 'toggleSupplier']);
                    // Procurement-prefixed aliases (FE wiring §5.5 — the SPA calls
                    // everything under its own /procurement prefix).
                    Route::post('procurement/suppliers', [ProcurementCompanyController::class, 'storeSupplier']);
                    Route::patch('procurement/suppliers/{id}', [ProcurementCompanyController::class, 'updateSupplier']);
                    Route::post('procurement/suppliers/{id}/toggle-active', [ProcurementCompanyController::class, 'toggleSupplier']);
                });
                Route::middleware('asab.role:procurement,branch')->group(function () {
                    Route::post('suppliers/{id}/ratings', [ProcurementCompanyController::class, 'rateSupplier']);
                    Route::post('procurement/suppliers/{id}/ratings', [ProcurementCompanyController::class, 'rateSupplier']);
                });

                // Cross-cutting (§7) — any company role
                Route::middleware('asab.role:company-admin,head,accountant,branch,procurement')->group(function () {
                    Route::get('notifications', [NotificationController::class, 'index']);
                    Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead']);
                    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
                    Route::delete('notifications/{id}', [CrossController::class, 'notificationDestroy']);

                    Route::get('lookups/brands', [LookupController::class, 'brands']);
                    Route::get('lookups/restaurants', [LookupController::class, 'restaurants']);
                    Route::get('lookups/branches', [LookupController::class, 'branches']);
                    Route::get('lookups/users', [LookupController::class, 'users']);
                    Route::get('lookups/cities', [CrossController::class, 'cities']);
                    Route::get('lookups/units', [CrossController::class, 'units']);
                    Route::get('lookups/asset-categories', [CrossController::class, 'assetCategories']);
                    Route::get('lookups/inventory-categories', [CrossController::class, 'inventoryCategories']);
                    Route::get('lookups/expense-categories', [CrossController::class, 'expenseCategories']);
                    Route::get('lookups/supplier-categories', [CrossController::class, 'supplierCategories']);

                    Route::get('reports/{key}/download', [CrossController::class, 'reportDownload']);
                    Route::get('reports', [ReportController::class, 'catalog']);
                    Route::get('procurement/reports', [ReportController::class, 'catalog']);
                    Route::get('procurement/reports/{key}/download', [CrossController::class, 'reportDownload']);
                    Route::get('search', [SearchController::class, 'index']);
                });

                // Audit log — company-admin (full) + head (read-only)
                Route::middleware('asab.role:company-admin,head')->get('audit-logs', [CrossController::class, 'auditLogs']);
            });

            // Per-user UI preferences + cross-cutting (§7 / §11)
            Route::middleware(['asab.tenant', 'asab.role:company-admin,head,accountant,branch,procurement'])
                ->prefix('users/me')->group(function () {
                    Route::patch('preferences', [CrossController::class, 'userPreferences']);

                    // Onboarding tour state (FE completion request §2.2)
                    Route::get('onboarding-state', [OnboardingController::class, 'show']);
                    Route::patch('onboarding-state', [OnboardingController::class, 'update']);

                    // Saved filter presets (§11.1)
                    Route::get('saved-filters', [SavedFilterController::class, 'index']);
                    Route::post('saved-filters', [SavedFilterController::class, 'store']);
                    Route::delete('saved-filters/{id}', [SavedFilterController::class, 'destroy']);

                    // Header quick-stats (§11.3)
                    Route::get('quick-stats', [QuickStatsController::class, 'index']);

                    // Persistent table column layout (§11.4)
                    Route::get('table-prefs', [TablePrefController::class, 'show']);
                    Route::put('table-prefs', [TablePrefController::class, 'upsert']);
                });
        });
    });
});
