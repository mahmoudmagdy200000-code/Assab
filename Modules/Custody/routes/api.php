<?php

use Illuminate\Support\Facades\Route;
use Modules\Custody\Http\Controllers\CustodyController;
use Modules\Custody\Http\Controllers\LedgerController;
use Modules\Custody\Http\Controllers\CustodyRequestController;
use Modules\Custody\Http\Controllers\CustodyTransactionController;
use Modules\Custody\Http\Controllers\CustodyBalanceController;
use Modules\Custody\Http\Controllers\CashierCustodyController;
use Modules\Custody\Http\Controllers\CustodyHandoverController;

Route::middleware(['auth:sanctum'])->group(function () {
    // Cashier Custody (cash in/out balance & transactions)
    Route::middleware(['cashier'])->prefix('cashier/custody')->group(function () {
        Route::get('balance', [CashierCustodyController::class, 'balance']);
        Route::get('transactions', [CashierCustodyController::class, 'transactions']);
    });
    // Personal Ledger Management (3.1.2.2) — Cashier & Branch Manager: data scoped to authenticated user (token)
    Route::middleware(['branch.manager.or.cashier'])->prefix('branch-manager/ledger')->group(function () {
        Route::get('personal-custody-balance', [LedgerController::class, 'getPersonalCustodyBalance']);
        Route::get('personal-balance-only', [LedgerController::class, 'getPersonalBalanceOnly']);
        Route::get('transactions', [LedgerController::class, 'getTransactions']);
        Route::post('export-pdf', [LedgerController::class, 'exportPdf']);
        Route::get('branch-custody-balance', [LedgerController::class, 'getBranchCustodyBalance']);
    });

    // Custody Management (3.1.2.3)
    Route::prefix('custody')->group(function () {
        // Requests
        Route::get('requests', [CustodyRequestController::class, 'index']);
        Route::get('requests/{requestId}', [CustodyRequestController::class, 'show']);
        Route::post('request-cashin', [CustodyRequestController::class, 'store']);
        Route::get('request-cashin/history', [CustodyRequestController::class, 'getHistory']);

        // Transactions
        Route::get('transactions', [CustodyTransactionController::class, 'index']);

        // Handover & Transfer
        Route::post('handover', [CustodyHandoverController::class, 'handover']);
        Route::post('transfer', [CustodyHandoverController::class, 'transfer']);
        Route::get('recipients', [CustodyHandoverController::class, 'getRecipients']);

        // Balance Trends
        Route::get('balance-trends', [CustodyBalanceController::class, 'getBalanceTrends']);
    });

    // Legacy route (keep for backward compatibility)
    Route::apiResource('custodies', CustodyController::class)->names('custody.legacy');
});
