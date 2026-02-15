<?php

use Illuminate\Support\Facades\Route;
use Modules\Expense\Http\Controllers\{
    ExpenseController,
    QuickCashExpenseController,
    SingleInvoiceExpenseController,
    GroupedInvoiceExpenseController,
    PreApprovalRequestController,
    ExpenseApprovalController,
    CategoryController,
    SupplierController,
    ExpenseAttachmentController
};

/*
|--------------------------------------------------------------------------
| Branch Manager - Expense Management Routes
|--------------------------------------------------------------------------
*/

Route::prefix('branch-manager/expenses')
    ->middleware(['auth:sanctum', 'branch.manager.or.cashier'])
    ->name('api.branch-manager.expenses.') // Add this line for route name prefix
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Categories & Suppliers
        |--------------------------------------------------------------------------
        */

        // Remove the explicit ->name() calls since we're using prefix
        Route::get('/categories', [CategoryController::class, 'index']);
        // Specific routes FIRST
        Route::get('/categories/parent-categories', [CategoryController::class, 'getParentCategories']);
        Route::get('/categories/{category}/subcategories', [CategoryController::class, 'getSubcategories']);
        // Then parameterized routes
        Route::get('/categories/{category}/children', [CategoryController::class, 'children']);
        Route::get('/categories/{category}', [CategoryController::class, 'show']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | Suppliers
        |--------------------------------------------------------------------------
        */

        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);

        /*
        |--------------------------------------------------------------------------
        | Quick Cash Expenses (< 500 SAR)
        |--------------------------------------------------------------------------
        */
        Route::prefix('quick-cash')->group(function () {
            Route::get('/', [ExpenseController::class, 'quickCashList']);
            Route::post('/', [QuickCashExpenseController::class, 'store']);
            Route::put('/{expense}', [QuickCashExpenseController::class, 'update']);
            Route::delete('/{expense}', [QuickCashExpenseController::class, 'destroy']);

            // Helper: Calculate VAT
            Route::post('/calculate-vat', [QuickCashExpenseController::class, 'calculateVAT']);
        });

        /*
        |--------------------------------------------------------------------------
        | Single Invoice Expenses (> 500 SAR)
        |--------------------------------------------------------------------------
        */
        Route::prefix('single-invoice')->group(function () {
            Route::get('/', [ExpenseController::class, 'singleInvoiceList']);
            Route::get('/previous', [SingleInvoiceExpenseController::class, 'getPreviousInvoices']);
            Route::post('/', [SingleInvoiceExpenseController::class, 'store']);
            Route::put('/{expense}', [SingleInvoiceExpenseController::class, 'update']);

            // duplicate
            Route::post('/{expense}/duplicate', [SingleInvoiceExpenseController::class, 'duplicate']);
        });

        /*
        |--------------------------------------------------------------------------
        | Pre-Approval Requests (> 500 SAR)
        |--------------------------------------------------------------------------
        */
        Route::prefix('pre-approval')->group(function () {
            Route::get('/', [ExpenseController::class, 'preApprovalList']);
            Route::post('/', [PreApprovalRequestController::class, 'store']);
            Route::put('/{expense}', [PreApprovalRequestController::class, 'update']);
            Route::get('/previous', [PreApprovalRequestController::class, 'getPreviousRequests']);
            Route::post('/{expense}/duplicate', [PreApprovalRequestController::class, 'duplicate']);
        });

        /*
        |--------------------------------------------------------------------------
        | Grouped Invoices (> 500 SAR total)
        |--------------------------------------------------------------------------
        */
        Route::prefix('grouped-invoice')->group(function () {
            Route::get('/', [ExpenseController::class, 'groupedInvoiceList']);
            Route::post('/', [GroupedInvoiceExpenseController::class, 'store']);
            Route::put('/{expense}', [GroupedInvoiceExpenseController::class, 'update']);
        });

        /*
        |--------------------------------------------------------------------------
        | General Expense Routes
        |--------------------------------------------------------------------------
        */
        Route::get('/', [ExpenseController::class, 'index']);
        Route::get('/summary', [ExpenseController::class, 'summary']);
        Route::get('/recent', [ExpenseController::class, 'recent']);
        Route::get('/drafts', [ExpenseController::class, 'drafts']);
        Route::get('/filter', [ExpenseController::class, 'filter']);
        Route::get('/search', [ExpenseController::class, 'search']);

        // Show / Timeline / Submit
        Route::get('/{expense}', [ExpenseController::class, 'show']);
        Route::get('/{expense}/timeline', [ExpenseController::class, 'timeline']);
        Route::post('/{expense}/submit', [ExpenseController::class, 'submit']);
        Route::post('/{expense}/resubmit', [ExpenseController::class, 'resubmit']);
        Route::delete('/{expense}', [ExpenseController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | Attachments Management
        |--------------------------------------------------------------------------
        */
        Route::prefix('{expense}/attachments')->group(function () {
            Route::get('/', [ExpenseAttachmentController::class, 'index']);
            Route::post('/', [ExpenseAttachmentController::class, 'store']);
            Route::delete('/', [ExpenseAttachmentController::class, 'destroy']);
        });

        /*
        |--------------------------------------------------------------------------
        | QR Code & Invoice Scanning
        |--------------------------------------------------------------------------
        */
        Route::post('/scan-qr', [ExpenseController::class, 'scanQRCode']);
        Route::post('/scan-invoice', [ExpenseController::class, 'scanInvoiceCode']);
    });

/*
|--------------------------------------------------------------------------
| Brand Owner - Expense Approval Routes
|--------------------------------------------------------------------------
*/

Route::prefix('brand-owner/expenses')
    ->middleware(['auth:sanctum', 'brand.owner'])
    ->name('api.brand-owner.expenses.') // Add this line for route name prefix
    ->group(function () {

        // View expenses
        Route::get('/', [ExpenseApprovalController::class, 'index']);
        Route::get('/{expense}', [ExpenseApprovalController::class, 'show']);

        // Mark as viewed
        Route::post('/{expense}/view', [ExpenseApprovalController::class, 'markAsViewed']);

        // Approve/Reject
        Route::post('/{expense}/approve', [ExpenseApprovalController::class, 'approve']);
        Route::post('/{expense}/reject', [ExpenseApprovalController::class, 'reject']);

        // Edit expense
        Route::put('/{expense}/edit', [ExpenseApprovalController::class, 'edit']);

        // Timeline
        Route::get('/{expense}/timeline', [ExpenseApprovalController::class, 'timeline']);
    });
