<?php

use Illuminate\Support\Facades\Route;
use Modules\Custody\Http\Controllers\CashierCustodyController;
use Modules\Custody\Http\Controllers\CustodyBalanceController;
use Modules\Custody\Http\Controllers\CustodyController;
use Modules\Custody\Http\Controllers\CustodyHandoverController;
use Modules\Custody\Http\Controllers\CustodyRequestController;
use Modules\Custody\Http\Controllers\CustodyTransactionController;
use Modules\Custody\Http\Controllers\LedgerController;

Route::middleware(['auth:sanctum', 'log.throttle'])->group(function () {
    // Cashier Custody (cash in/out balance & transactions)
    Route::middleware(['cashier'])->prefix('cashier/custody')->group(function () {
        Route::get('balance', [CashierCustodyController::class, 'balance']);
        Route::get('transactions', [CashierCustodyController::class, 'transactions']);
    });
    // Personal Ledger Management (3.1.2.2) — Cashier, Branch Manager, Brand Owner (owner payment form)
    Route::middleware(['branch.manager.or.cashier.or.brand.owner'])->prefix('branch-manager/ledger')->group(function () {
        Route::get('personal-custody-balance', [LedgerController::class, 'getPersonalCustodyBalance']);
        Route::get('personal-balance-only', [LedgerController::class, 'getPersonalBalanceOnly']);
        Route::get('transactions', [LedgerController::class, 'getTransactions']);
    });
    // PDF export + branch custody balance remain BM/Cashier only
    Route::middleware(['branch.manager.or.cashier'])->prefix('branch-manager/ledger')->group(function () {
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

        // Approve / Reject (brand-owner only — enforced inside controller)
        Route::post('requests/{requestId}/approve', [CustodyRequestController::class, 'approve'])->middleware(['throttle:custody-write']);
        Route::post('requests/{requestId}/reject', [CustodyRequestController::class, 'reject'])->middleware(['throttle:custody-write']);

        // Receipt confirmation (branch-manager only — enforced inside controller).
        // The step that actually credits «رصيد عهدة الفرع».
        Route::post('requests/{requestId}/confirm-receipt', [CustodyRequestController::class, 'confirmReceipt'])->middleware(['throttle:custody-write']);

        // Transactions
        Route::get('transactions', [CustodyTransactionController::class, 'index']);

        // Handover & Transfer
        Route::post('handover', [CustodyHandoverController::class, 'handover'])->middleware(['throttle:custody-write']);
        Route::post('transfer', [CustodyHandoverController::class, 'transfer'])->middleware(['throttle:custody-write']);
        Route::get('recipients', [CustodyHandoverController::class, 'getRecipients']);
        // Custody handover requests (cashier-to-cashier, pending until accept/reject)
        Route::get('handover-requests', [CustodyHandoverController::class, 'indexHandoverRequests']);
        Route::post('handover-requests/{id}/accept', [CustodyHandoverController::class, 'acceptHandoverRequest'])->middleware(['throttle:custody-write']);
        Route::post('handover-requests/{id}/reject', [CustodyHandoverController::class, 'rejectHandoverRequest'])->middleware(['throttle:custody-write']);

        // Balance Trends
        Route::get('balance-trends', [CustodyBalanceController::class, 'getBalanceTrends']);
    });

    // Legacy route (keep for backward compatibility)
    Route::apiResource('custodies', CustodyController::class)->names('custody.legacy');
});
