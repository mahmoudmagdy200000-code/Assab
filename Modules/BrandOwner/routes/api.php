<?php

use Illuminate\Support\Facades\Route;
use Modules\BrandOwner\Http\Controllers\AuthController;
use Modules\BrandOwner\Http\Controllers\BrandManagerAuthController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerAssetOverviewController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerBranchesController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerControlPanelController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerFixedAssetsDisposalController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerFixedAssetsHandoverReportController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerFixedAssetsModificationController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerFixedAssetsReviewAuditController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerFixedAssetsTransferController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerHomeController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerInventoryController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerReportsController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerReturnController;
use Modules\BrandOwner\Http\Controllers\BrandOwnerSettingsController;
use Modules\BrandOwner\Http\Controllers\CashSalesTransferController;
use Modules\BrandOwner\Http\Controllers\Financial\BreakEvenAnalysisController;
use Modules\BrandOwner\Http\Controllers\Financial\ItemTestController;
use Modules\BrandOwner\Http\Controllers\Financial\MenuEngineeringController;
use Modules\BrandOwner\Http\Controllers\Financial\OperationalProfitabilityController;
use Modules\BrandOwner\Http\Controllers\Financial\PriceSimulatorController;
use Modules\BrandOwner\Http\Controllers\Financial\ProfitAndLossController;
use Modules\BrandOwner\Http\Controllers\Financial\ProfitVsCashReconciliationController;
use Modules\BrandOwner\Http\Controllers\Financial\SalesChannelController;
use Modules\BrandOwner\Http\Controllers\Financial\SmartComparisonController;
use Modules\BrandOwner\Http\Controllers\OwnerPaymentFormController;
use Modules\BrandOwner\Http\Controllers\OwnerPaymentLogController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('brandowners', BrandOwnerController::class)->names('brandowner');
});

/*
|--------------------------------------------------------------------------
| Brand Owner - Authentication (mirrors Branch Manager auth)
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Brand Manager - Authentication
|--------------------------------------------------------------------------
| Brand Manager extends Brand Owner and reuses every /brand-owner/* endpoint
| (instanceof BrandOwner stays true). Auth lives under its own prefix.
*/

Route::prefix('brand-manager')->group(function () {
    Route::post('auth/login', [BrandManagerAuthController::class, 'login']);
    Route::post('auth/forgot-password', [BrandManagerAuthController::class, 'forgotPassword']);
    Route::post('auth/verify-otp', [BrandManagerAuthController::class, 'verifyOtp']);
    Route::post('auth/reset-password', [BrandManagerAuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [BrandManagerAuthController::class, 'logout']);
        Route::get('auth/me', [BrandManagerAuthController::class, 'me']);
    });
});

Route::prefix('brand-owner')->group(function () {
    // Public
    Route::post('auth/first-login', [AuthController::class, 'firstLogin']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
    // «activate Account» — the handler demands a proof (first-login token in the
    // header or body, or the default password), so it does not need auth:sanctum
    // and no longer dead-ends a build that omits the Bearer header.
    Route::post('auth/reset-password-first-login', [AuthController::class, 'resetPasswordFirstLogin'])
        ->middleware('throttle:6,1');

    // Protected
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Brand Owner - Cash Sales Transfer
|--------------------------------------------------------------------------
| Brand-owner specific endpoints for the cash sales transfer details screen
| plus approve/reject actions.
*/

Route::prefix('brand-owner/cash-sales-transfers')
    ->middleware(['auth:sanctum', 'brand.owner', 'log.throttle'])
    ->name('api.brand-owner.cash-sales-transfers.')
    ->group(function () {
        Route::get('/', [CashSalesTransferController::class, 'index']);
        Route::get('/{id}', [CashSalesTransferController::class, 'show'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('/{id}/approve', [CashSalesTransferController::class, 'approve'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('/{id}/reject', [CashSalesTransferController::class, 'reject'])
            ->where('id', '[0-9a-f-]{36}');
    });

/*
|--------------------------------------------------------------------------
| Brand Owner - Payment Form (submit) + Payment Logs
|--------------------------------------------------------------------------
*/

Route::prefix('brand-owner')
    ->middleware(['auth:sanctum', 'brand.owner', 'log.throttle'])
    ->name('api.brand-owner.')
    ->group(function () {
        Route::post('payment-form', [OwnerPaymentFormController::class, 'store']);

        Route::get('payment-logs', [OwnerPaymentLogController::class, 'index']);
        Route::get('payment-logs/{paymentLogId}', [OwnerPaymentLogController::class, 'show'])
            ->where('paymentLogId', '[0-9a-f-]{36}');
    });

/*
|--------------------------------------------------------------------------
| Brand Owner - Inventory (BrandManagerInventoryManagementScreen)
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Brand Owner - Purchase Return Escalations
|--------------------------------------------------------------------------
| Approve / reject an escalated purchase return. Listing + details for
| escalated returns are served by the shared /purchase/returns endpoints
| (controller branches on user type).
*/

Route::prefix('brand-owner/purchase/returns')
    ->middleware(['auth:sanctum', 'brand.owner', 'log.throttle'])
    ->name('api.brand-owner.purchase.returns.')
    ->group(function () {
        Route::post('/{returnId}/approve-escalation', [BrandOwnerReturnController::class, 'approveEscalation'])
            ->where('returnId', '[0-9a-f-]{36}')
            ->name('approve-escalation');
        Route::post('/{returnId}/reject-escalation', [BrandOwnerReturnController::class, 'rejectEscalation'])
            ->where('returnId', '[0-9a-f-]{36}')
            ->name('reject-escalation');
    });

Route::prefix('brand-owner/inventory')
    ->middleware(['auth:sanctum', 'brand.owner'])
    ->name('api.brand-owner.inventory.')
    ->group(function () {
        Route::get('daily-requests', [BrandOwnerInventoryController::class, 'dailyIndex']);
        Route::get('daily-requests/{requestId}', [BrandOwnerInventoryController::class, 'dailyShow'])
            ->where('requestId', '[0-9a-f-]{36}');
        Route::post('daily-requests/{requestId}/approve', [BrandOwnerInventoryController::class, 'dailyApprove'])
            ->where('requestId', '[0-9a-f-]{36}');
        Route::post('daily-requests/{requestId}/reject', [BrandOwnerInventoryController::class, 'dailyReject'])
            ->where('requestId', '[0-9a-f-]{36}');

        Route::get('waste-damage-requests', [BrandOwnerInventoryController::class, 'wasteDamageIndex']);
        Route::get('waste-damage-requests/{requestId}', [BrandOwnerInventoryController::class, 'wasteDamageShow'])
            ->where('requestId', '[0-9a-f-]{36}');
        Route::post('waste-damage-requests/{requestId}/approve', [BrandOwnerInventoryController::class, 'wasteDamageApprove'])
            ->where('requestId', '[0-9a-f-]{36}');
        Route::post('waste-damage-requests/{requestId}/reject', [BrandOwnerInventoryController::class, 'wasteDamageReject'])
            ->where('requestId', '[0-9a-f-]{36}');
    });

/*
|--------------------------------------------------------------------------
| Brand Owner - Reports & Analytics (BrandOwnerReportsAndAnalyticsScreen)
|--------------------------------------------------------------------------
| Expense / custody reports, report details, exports, and the branch list
| used by report filters.
|
| Every endpoint here is reachable by both brand owners and branch managers
| (each controller branches on the authenticated user type). A branch manager
| is always scoped to their own branch — branch list, report details, exports
| and export history all return that branch only. Brand-owner behaviour is
| unchanged: they continue to see every branch.
*/

Route::prefix('brand-owner')
    ->middleware(['auth:sanctum', 'branch.manager.or.brand.owner', 'log.throttle'])
    ->name('api.brand-owner.')
    ->group(function () {
        Route::get('branches', [BrandOwnerBranchesController::class, 'index'])
            ->name('branches');

        Route::get('reports-and-analytics', [BrandOwnerReportsController::class, 'index'])
            ->name('reports-and-analytics');

        Route::get('reports/expense/{reportId}', [BrandOwnerReportsController::class, 'expenseDetails'])
            ->name('reports.expense.show');
        Route::get('reports/custody/{reportId}', [BrandOwnerReportsController::class, 'custodyDetails'])
            ->name('reports.custody.show');

        Route::post('reports/expense/export', [BrandOwnerReportsController::class, 'exportExpense'])
            ->name('reports.expense.export');
        Route::post('reports/custody/export', [BrandOwnerReportsController::class, 'exportCustody'])
            ->name('reports.custody.export');
    });

/*
|--------------------------------------------------------------------------
| Brand Owner - Home Dashboard (BrandOwnerHomeScreen)
|--------------------------------------------------------------------------
| Branch list for the home branch selector plus invoice / expense summaries
| and daily / weekly / monthly expense trend charts.
*/

Route::prefix('brand-owner/dashboard')
    ->middleware(['auth:sanctum', 'brand.owner', 'log.throttle'])
    ->name('api.brand-owner.dashboard.')
    ->group(function () {
        Route::get('branches', [BrandOwnerHomeController::class, 'branches'])
            ->name('branches');

        Route::get('/', [BrandOwnerHomeController::class, 'dashboard'])
            ->name('index');
    });

/*
|--------------------------------------------------------------------------
| Brand Owner - Fixed Assets Requests
|--------------------------------------------------------------------------
*/

Route::prefix('brand-owner/fixed-assets/requests')
    ->middleware(['auth:sanctum', 'brand.owner'])
    ->name('api.brand-owner.fixed-assets.requests.')
    ->group(function () {
        // Modification
        Route::get('modification', [BrandOwnerFixedAssetsModificationController::class, 'index']);
        Route::get('modification/{id}', [BrandOwnerFixedAssetsModificationController::class, 'show'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('modification/{id}/approve', [BrandOwnerFixedAssetsModificationController::class, 'approve'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('modification/{id}/reject', [BrandOwnerFixedAssetsModificationController::class, 'reject'])
            ->where('id', '[0-9a-f-]{36}');

        // Transfer (branch-to-branch)
        Route::get('transfer', [BrandOwnerFixedAssetsTransferController::class, 'index']);
        Route::get('transfer/{id}', [BrandOwnerFixedAssetsTransferController::class, 'show'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('transfer/{id}/approve', [BrandOwnerFixedAssetsTransferController::class, 'approve'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('transfer/{id}/reject', [BrandOwnerFixedAssetsTransferController::class, 'reject'])
            ->where('id', '[0-9a-f-]{36}');

        // Disposal & external transfer
        Route::get('disposal-and-external-transfer', [BrandOwnerFixedAssetsDisposalController::class, 'index']);
        Route::get('disposal-and-external-transfer/{id}', [BrandOwnerFixedAssetsDisposalController::class, 'show'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('disposal-and-external-transfer/{id}/approve', [BrandOwnerFixedAssetsDisposalController::class, 'approve'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('disposal-and-external-transfer/{id}/reject', [BrandOwnerFixedAssetsDisposalController::class, 'reject'])
            ->where('id', '[0-9a-f-]{36}');

        // Handover reports (Major Discrepancy)
        Route::get('handover-reports', [BrandOwnerFixedAssetsHandoverReportController::class, 'index']);
        Route::get('handover-reports/{id}', [BrandOwnerFixedAssetsHandoverReportController::class, 'show'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('handover-reports/{id}/approve', [BrandOwnerFixedAssetsHandoverReportController::class, 'approve'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('handover-reports/{id}/salary-deduction', [BrandOwnerFixedAssetsHandoverReportController::class, 'salaryDeduction'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('handover-reports/{id}/reject', [BrandOwnerFixedAssetsHandoverReportController::class, 'reject'])
            ->where('id', '[0-9a-f-]{36}');

        // Review & Audit
        Route::get('review-audit', [BrandOwnerFixedAssetsReviewAuditController::class, 'index']);
        Route::get('review-audit/{id}', [BrandOwnerFixedAssetsReviewAuditController::class, 'show'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('review-audit/{id}/approve', [BrandOwnerFixedAssetsReviewAuditController::class, 'approve'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('review-audit/{id}/reject', [BrandOwnerFixedAssetsReviewAuditController::class, 'reject'])
            ->where('id', '[0-9a-f-]{36}');
    });

/*
|--------------------------------------------------------------------------
| Brand Owner - Settings
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Brand Owner - Asset Overview & Control Panel
|--------------------------------------------------------------------------
*/

Route::prefix('brand-owner')
    ->middleware(['auth:sanctum', 'brand.owner'])
    ->name('api.brand-owner.')
    ->group(function () {
        Route::get('asset-overview', [BrandOwnerAssetOverviewController::class, 'index'])
            ->name('asset-overview.index');
        Route::get('asset-overview/performance-summary', [BrandOwnerAssetOverviewController::class, 'performanceSummary'])
            ->name('asset-overview.performance-summary');
        Route::get('asset-overview/branch/{branchId}', [BrandOwnerAssetOverviewController::class, 'branchDetails'])
            ->name('asset-overview.branch');
        Route::post('asset-overview/export', [BrandOwnerAssetOverviewController::class, 'export'])
            ->name('asset-overview.export');

        Route::get('control-panel', [BrandOwnerControlPanelController::class, 'index'])
            ->name('control-panel');
    });

Route::prefix('brand-owner/settings')
    ->middleware(['auth:sanctum', 'brand.owner'])
    ->name('api.brand-owner.settings.')
    ->group(function () {
        Route::get('approval', [BrandOwnerSettingsController::class, 'showApproval']);
        Route::patch('approval', [BrandOwnerSettingsController::class, 'updateApproval']);

        Route::get('report', [BrandOwnerSettingsController::class, 'showReport']);
        Route::patch('report', [BrandOwnerSettingsController::class, 'updateReport']);

        Route::get('security', [BrandOwnerSettingsController::class, 'showSecurity']);
        Route::patch('security', [BrandOwnerSettingsController::class, 'updateSecurity']);

        Route::get('retention', [BrandOwnerSettingsController::class, 'showRetention']);
        Route::patch('retention', [BrandOwnerSettingsController::class, 'updateRetention']);

        Route::get('notifications', [BrandOwnerSettingsController::class, 'showNotifications']);
        Route::patch('notifications', [BrandOwnerSettingsController::class, 'updateNotifications']);
    });

/*
|--------------------------------------------------------------------------
| Brand Owner - Financial Reporting (BrandOwnerFinancialReportingScreen)
|--------------------------------------------------------------------------
| Core reports (P&L, sales-channel, smart comparison), specialised reports
| (profit-vs-cash, break-even, operational profitability, menu engineering)
| and the menu-engineering actions (item test, price simulator), each with
| PDF/Excel export and email delivery. Spec: brand-owner-financial-reporting-doc.
*/

Route::prefix('brand-owner/financial')
    ->middleware(['auth:sanctum', 'brand.owner', 'log.throttle'])
    ->name('api.brand-owner.financial.')
    ->group(function () {
        // 1. Profit & Loss Statement
        Route::get('profit-and-loss', [ProfitAndLossController::class, 'index']);
        Route::post('profit-and-loss/export', [ProfitAndLossController::class, 'export']);
        Route::post('profit-and-loss/email', [ProfitAndLossController::class, 'email']);

        // 2/3. Sales Channel Analysis + Level 2
        Route::get('sales-channel-analysis', [SalesChannelController::class, 'analysis']);
        Route::post('sales-channel-analysis/export', [SalesChannelController::class, 'analysisExport']);
        Route::post('sales-channel-analysis/email', [SalesChannelController::class, 'analysisEmail']);
        Route::get('sales-channel-level2', [SalesChannelController::class, 'level2']);
        Route::post('sales-channel-level2/export', [SalesChannelController::class, 'level2Export']);

        // 4. Smart Comparisons
        Route::get('smart-comparison', [SmartComparisonController::class, 'index']);
        Route::post('smart-comparison/export', [SmartComparisonController::class, 'export']);
        Route::post('smart-comparison/email', [SmartComparisonController::class, 'email']);

        // 5. Profit vs Cash Reconciliation
        Route::get('profit-vs-cash-reconciliation', [ProfitVsCashReconciliationController::class, 'index']);
        Route::post('profit-vs-cash-reconciliation/export', [ProfitVsCashReconciliationController::class, 'export']);
        Route::post('profit-vs-cash-reconciliation/email', [ProfitVsCashReconciliationController::class, 'email']);

        // 6. Break-Even Analysis
        Route::get('break-even-analysis', [BreakEvenAnalysisController::class, 'index']);
        Route::post('break-even-analysis/export', [BreakEvenAnalysisController::class, 'export']);

        // 7. Operational Profitability
        Route::get('operational-profitability', [OperationalProfitabilityController::class, 'index']);
        Route::post('operational-profitability/export', [OperationalProfitabilityController::class, 'export']);
        Route::post('operational-profitability/email', [OperationalProfitabilityController::class, 'email']);

        // 8. Menu Engineering
        Route::get('menu-engineering', [MenuEngineeringController::class, 'index']);
        Route::post('menu-engineering/export', [MenuEngineeringController::class, 'export']);
        Route::post('menu-engineering/email', [MenuEngineeringController::class, 'email']);

        // 9. Item Test
        Route::post('item-test/submit', [ItemTestController::class, 'submit']);
        Route::get('item-test/saved-tests', [ItemTestController::class, 'savedTests']);
        Route::post('item-test/export', [ItemTestController::class, 'export']);
        Route::post('item-test/email', [ItemTestController::class, 'email']);

        // 10. Price Simulator
        Route::get('price-simulator/items', [PriceSimulatorController::class, 'items']);
        Route::get('price-simulator/item-info', [PriceSimulatorController::class, 'itemInfo']);
        Route::post('price-simulator/simulate', [PriceSimulatorController::class, 'simulate']);
        Route::get('price-simulator/saved-scenarios', [PriceSimulatorController::class, 'savedScenarios']);
        Route::post('price-simulator/export', [PriceSimulatorController::class, 'export']);
        Route::post('price-simulator/email', [PriceSimulatorController::class, 'email']);
    });
