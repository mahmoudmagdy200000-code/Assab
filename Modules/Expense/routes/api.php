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
    SupplierController
};

/*
|--------------------------------------------------------------------------
| Branch Manager - Expense Management Routes
|--------------------------------------------------------------------------
*/

Route::prefix('branch-manager/expenses')
    ->middleware(['auth:sanctum', 'branch_manager'])
    ->group(function () {

    /*
    |----------------------------------------------------------------------
    | General Expense Routes
    |----------------------------------------------------------------------
    */
    // Expense summary and list
    Route::get('/', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/summary', [ExpenseController::class, 'summary'])->name('expenses.summary');
    Route::get('/recent', [ExpenseController::class, 'recent'])->name('expenses.recent');
    Route::get('/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');

    // Timeline
    Route::get('/{expense}/timeline', [ExpenseController::class, 'timeline'])->name('expenses.timeline');

    // Submit/Resubmit
    Route::post('/{expense}/submit', [ExpenseController::class, 'submit'])->name('expenses.submit');
    Route::post('/{expense}/resubmit', [ExpenseController::class, 'resubmit'])->name('expenses.resubmit');

    /*
    |----------------------------------------------------------------------
    | Draft Expenses
    |----------------------------------------------------------------------
    */
    Route::get('/drafts', [ExpenseController::class, 'drafts'])->name('expenses.drafts');
    Route::delete('/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

    /*
    |----------------------------------------------------------------------
    | Filter and Search
    |----------------------------------------------------------------------
    */
    Route::get('/filter', [ExpenseController::class, 'filter'])->name('expenses.filter');

    /*
    |----------------------------------------------------------------------
    | Quick Cash Expenses (< 500 SAR)
    |----------------------------------------------------------------------
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
    |----------------------------------------------------------------------
    | Single Invoice Expenses (> 500 SAR)
    |----------------------------------------------------------------------
    */
    Route::prefix('single-invoice')->group(function () {
        Route::get('/', [ExpenseController::class, 'singleInvoiceList'])->name('expenses.single-invoice.index');
        Route::post('/', [SingleInvoiceExpenseController::class, 'store'])->name('expenses.single-invoice.store');
        Route::put('/{expense}', [SingleInvoiceExpenseController::class, 'update'])->name('expenses.single-invoice.update');

        // Get previous invoices for reuse
        Route::get('/previous', [SingleInvoiceExpenseController::class, 'getPreviousInvoices'])->name('expenses.single-invoice.previous');
    });

    /*
    |----------------------------------------------------------------------
    | Pre-Approval Requests (> 500 SAR)
    |----------------------------------------------------------------------
    */
    Route::prefix('pre-approval')->group(function () {
        Route::get('/', [ExpenseController::class, 'preApprovalList'])->name('expenses.pre-approval.index');
        Route::post('/', [PreApprovalRequestController::class, 'store'])->name('expenses.pre-approval.store');
        Route::put('/{expense}', [PreApprovalRequestController::class, 'update'])->name('expenses.pre-approval.update');
    });

    /*
    |----------------------------------------------------------------------
    | Grouped Invoices (> 500 SAR total)
    |----------------------------------------------------------------------
    */
    Route::prefix('grouped-invoice')->group(function () {
        Route::get('/', [ExpenseController::class, 'groupedInvoiceList'])->name('expenses.grouped-invoice.index');
        Route::post('/', [GroupedInvoiceExpenseController::class, 'store'])->name('expenses.grouped-invoice.store');
        Route::put('/{expense}', [GroupedInvoiceExpenseController::class, 'update'])->name('expenses.grouped-invoice.update');
    });

    /*
    |----------------------------------------------------------------------
    | Categories (For Items and Expenses)
    |----------------------------------------------------------------------
    */
    Route::get('/categories', [CategoryController::class, 'index'])->name('expenses.categories.index');
    Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('expenses.categories.show');

    /*
    |----------------------------------------------------------------------
    | Suppliers
    |----------------------------------------------------------------------
    */
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('expenses.suppliers.index');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('expenses.suppliers.show');

    /*
    |----------------------------------------------------------------------
    | QR Code & Invoice Scanning
    |----------------------------------------------------------------------
    */
    Route::post('/scan-qr', [ExpenseController::class, 'scanQRCode'])->name('expenses.scan-qr');
    Route::post('/scan-invoice', [ExpenseController::class, 'scanInvoiceCode'])->name('expenses.scan-invoice');
});

/*
|--------------------------------------------------------------------------
| Brand Owner - Expense Approval Routes
|--------------------------------------------------------------------------
*/

Route::prefix('api/brand-owner/expenses')
    ->middleware(['auth:sanctum', 'brand_owner'])
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
