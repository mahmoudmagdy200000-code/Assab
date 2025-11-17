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
    ->middleware(['auth:sanctum', 'branch.manager'])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Categories & Suppliers
        |--------------------------------------------------------------------------
        */

        Route::get('/categories', [CategoryController::class, 'index'])->name('expenses.categories.index');
        Route::get('/categories/{category}/children', [CategoryController::class, 'children'])->name('expenses.categories.children');
        Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('expenses.categories.show');

        Route::post('/categories', [CategoryController::class, 'store'])->name('expenses.categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('expenses.categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('expenses.categories.destroy');

        /*
        |--------------------------------------------------------------------------
        | Suppliers
        |--------------------------------------------------------------------------
        */

        Route::get('/suppliers', [SupplierController::class, 'index'])->name('expenses.suppliers.index');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('expenses.suppliers.show');

        /*
        |--------------------------------------------------------------------------
        | Quick Cash Expenses (< 500 SAR)
        |--------------------------------------------------------------------------
        */
        Route::prefix('quick-cash')->group(function () {
            Route::get('/', [ExpenseController::class, 'quickCashList'])->name('expenses.quick-cash.index');
            Route::post('/', [QuickCashExpenseController::class, 'store'])->name('expenses.quick-cash.store');
            Route::put('/{expense}', [QuickCashExpenseController::class, 'update'])->name('expenses.quick-cash.update');
            Route::delete('/{expense}', [QuickCashExpenseController::class, 'destroy'])->name('expenses.quick-cash.destroy');

            // Helper: Calculate VAT
            Route::post('/calculate-vat', [QuickCashExpenseController::class, 'calculateVAT'])->name('expenses.quick-cash.calculate-vat');
        });

        /*
        |--------------------------------------------------------------------------
        | Single Invoice Expenses (> 500 SAR)
        |--------------------------------------------------------------------------
        */
        Route::prefix('single-invoice')->group(function () {
            Route::get('/', [ExpenseController::class, 'singleInvoiceList'])->name('expenses.single-invoice.index');
            Route::get('/previous', [SingleInvoiceExpenseController::class, 'getPreviousInvoices'])->name('expenses.single-invoice.previous');
            Route::post('/', [SingleInvoiceExpenseController::class, 'store'])->name('expenses.single-invoice.store');
            Route::put('/{expense}', [SingleInvoiceExpenseController::class, 'update'])->name('expenses.single-invoice.update');

            // duplicate
            Route::post('/{expense}/duplicate', [SingleInvoiceExpenseController::class, 'duplicate'])->name('expenses.single-invoice.duplicate');
        });

        /*
        |--------------------------------------------------------------------------
        | Pre-Approval Requests (> 500 SAR)
        |--------------------------------------------------------------------------
        */
        Route::prefix('pre-approval')->group(function () {
            Route::get('/', [ExpenseController::class, 'preApprovalList'])->name('expenses.pre-approval.index');
            Route::post('/', [PreApprovalRequestController::class, 'store'])->name('expenses.pre-approval.store');
            Route::put('/{expense}', [PreApprovalRequestController::class, 'update'])->name('expenses.pre-approval.update');
            Route::get('/previous', [PreApprovalRequestController::class, 'getPreviousRequests'])->name('expenses.pre-approval.previous');
            Route::post('/{expense}/duplicate', [PreApprovalRequestController::class, 'duplicate'])->name('expenses.pre-approval.duplicate');
        });

        /*
        |--------------------------------------------------------------------------
        | Grouped Invoices (> 500 SAR total)
        |--------------------------------------------------------------------------
        */
        Route::prefix('grouped-invoice')->group(function () {
            Route::get('/', [ExpenseController::class, 'groupedInvoiceList'])->name('expenses.grouped-invoice.index');
            Route::post('/', [GroupedInvoiceExpenseController::class, 'store'])->name('expenses.grouped-invoice.store');
            Route::put('/{expense}', [GroupedInvoiceExpenseController::class, 'update'])->name('expenses.grouped-invoice.update');
        });

        /*
        |--------------------------------------------------------------------------
        | General Expense Routes
        |--------------------------------------------------------------------------
        */
        Route::get('/', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/summary', [ExpenseController::class, 'summary'])->name('expenses.summary');
        Route::get('/recent', [ExpenseController::class, 'recent'])->name('expenses.recent');
        Route::get('/drafts', [ExpenseController::class, 'drafts'])->name('expenses.drafts');
        Route::get('/filter', [ExpenseController::class, 'filter'])->name('expenses.filter');
        Route::get('/search', [ExpenseController::class, 'search'])->name('expenses.search');

        // Show / Timeline / Submit
        Route::get('/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
        Route::get('/{expense}/timeline', [ExpenseController::class, 'timeline'])->name('expenses.timeline');
        Route::post('/{expense}/submit', [ExpenseController::class, 'submit'])->name('expenses.submit');
        Route::post('/{expense}/resubmit', [ExpenseController::class, 'resubmit'])->name('expenses.resubmit');
        Route::delete('/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

        /*
        |--------------------------------------------------------------------------
        | NEW - Delete Attachments
        |--------------------------------------------------------------------------
        */
        // Route::delete('/{expense}/attachments', [ExpenseAttachmentController::class, 'delete'])
        //     ->name('expenses.attachments.delete');

        /*
        |--------------------------------------------------------------------------
        | QR Code & Invoice Scanning
        |--------------------------------------------------------------------------
        */
        Route::post('/scan-qr', [ExpenseController::class, 'scanQRCode'])->name('expenses.scan-qr');
        Route::post('/scan-invoice', [ExpenseController::class, 'scanInvoiceCode'])->name('expenses.scan-invoice');
    });

/*
|--------------------------------------------------------------------------
| Brand Owner - Expense Approval Routes
|--------------------------------------------------------------------------
*/

Route::prefix('brand-owner/expenses')
    ->middleware(['auth:sanctum', 'brand.owner'])
    ->group(function () {

        // View expenses
        Route::get('/', [ExpenseApprovalController::class, 'index'])->name('brand-owner.expenses.index');
        Route::get('/{expense}', [ExpenseApprovalController::class, 'show'])->name('brand-owner.expenses.show');

        // Mark as viewed
        Route::post('/{expense}/view', [ExpenseApprovalController::class, 'markAsViewed'])->name('brand-owner.expenses.view');

        // Approve/Reject
        Route::post('/{expense}/approve', [ExpenseApprovalController::class, 'approve'])->name('brand-owner.expenses.approve');
        Route::post('/{expense}/reject', [ExpenseApprovalController::class, 'reject'])->name('brand-owner.expenses.reject');

        // Edit expense
        Route::put('/{expense}/edit', [ExpenseApprovalController::class, 'edit'])->name('brand-owner.expenses.edit');

        // Timeline
        Route::get('/{expense}/timeline', [ExpenseApprovalController::class, 'timeline'])->name('brand-owner.expenses.timeline');
    });
