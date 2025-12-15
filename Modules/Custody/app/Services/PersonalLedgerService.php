<?php

namespace Modules\Custody\Services;

use Illuminate\Support\Facades\DB;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\BranchManagerShift;

class PersonalLedgerService
{
    /**
     * Get personal custody balance summary
     */
    public function getPersonalCustodyBalance(string $branchManagerId): array
    {
        $transactions = PersonalLedgerTransaction::where('branch_manager_id', $branchManagerId)
            ->orderBy('transaction_date', 'desc')
            ->get();

        $totalCashIn = $transactions->where('is_cash_in', true)->sum('amount');
        $totalCashOut = $transactions->where('is_cash_in', false)->sum('amount');
        $currentBalance = $totalCashIn - $totalCashOut;

        $recentActivity = $transactions->take(5)->map(function ($transaction) {
            return $this->formatTransactionForActivity($transaction);
        })->values();

        return [
            'totalCashIn' => round($totalCashIn, 2),
            'totalCashOut' => round($totalCashOut, 2),
            'currentBalance' => round($currentBalance, 2),
            'recentActivity' => $recentActivity,
        ];
    }

    /**
     * Get personal balance only (for forms)
     */
    public function getPersonalBalanceOnly(string $branchManagerId): float
    {
        $transactions = PersonalLedgerTransaction::where('branch_manager_id', $branchManagerId)->get();

        $totalCashIn = $transactions->where('is_cash_in', true)->sum('amount');
        $totalCashOut = $transactions->where('is_cash_in', false)->sum('amount');

        return round($totalCashIn - $totalCashOut, 2);
    }

    /**
     * Get transaction history with filters
     */
    public function getTransactionHistory(string $branchManagerId, array $filters = []): array
    {
        $query = PersonalLedgerTransaction::where('branch_manager_id', $branchManagerId);

        // View filter
        $view = $filters['view'] ?? 'detailed';
        if ($view === 'daily') {
            $query->whereDate('transaction_date', today());
        }

        // Date range filter
        if (!empty($filters['startDate'])) {
            $query->whereDate('transaction_date', '>=', $filters['startDate']);
        }
        if (!empty($filters['endDate'])) {
            $query->whereDate('transaction_date', '<=', $filters['endDate']);
        }

        // Transaction type filter
        if (!empty($filters['transactionType'])) {
            $query->where('transaction_type', $filters['transactionType']);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();

        return [
            'view' => $view,
            'totalTransactions' => $transactions->count(),
            'transactions' => $transactions->map(function ($transaction) {
                return $this->formatTransactionForList($transaction);
            })->values(),
        ];
    }

    /**
     * Create transaction from approved handover
     */
    public function createTransactionFromHandover(CashierShiftHandover $handover): PersonalLedgerTransaction
    {
        $cashier = $handover->cashierShift->cashier;

        return PersonalLedgerTransaction::create([
            'branch_manager_id' => $handover->handover_to_id,
            'transaction_type' => 'Total Sales',
            'amount' => $handover->handover_amount,
            'is_cash_in' => true,
            'cashier_name' => $cashier->name ?? null,
            'related_shift_id' => $handover->cashier_shift_id,
            'related_handover_id' => $handover->id,
            'transaction_date' => $handover->handover_date ?? now(),
        ]);
    }

    /**
     * Format transaction for activity list
     */
    private function formatTransactionForActivity(PersonalLedgerTransaction $transaction): array
    {
        $amount = $transaction->is_cash_in
            ? '+' . number_format($transaction->amount, 2, '.', '')
            : '-' . number_format($transaction->amount, 2, '.', '');

        $data = [
            'transactionType' => $transaction->transaction_type,
            'amount' => $amount,
            'dateTime' => $transaction->transaction_date->toIso8601String(),
            'isCashIn' => $transaction->is_cash_in,
        ];

        if ($transaction->transaction_type === 'Total Sales' && $transaction->cashier_name) {
            $data['cashierName'] = $transaction->cashier_name;
        }

        if ($transaction->transaction_type === 'Handover to Brand Owner' && $transaction->brand_owner_name) {
            $data['brandOwnerName'] = $transaction->brand_owner_name;
        }

        return $data;
    }

    /**
     * Format transaction for list view
     */
    private function formatTransactionForList(PersonalLedgerTransaction $transaction): array
    {
        $amount = $transaction->is_cash_in
            ? '+' . number_format($transaction->amount, 2, '.', '')
            : '-' . number_format($transaction->amount, 2, '.', '');

        $data = [
            'id' => $transaction->id,
            'transactionType' => $transaction->transaction_type,
            'amount' => $amount,
            'dateTime' => $transaction->transaction_date->toIso8601String(),
        ];

        if ($transaction->transaction_type === 'Total Sales' && $transaction->cashier_name) {
            $data['cashierName'] = $transaction->cashier_name;
        }

        if ($transaction->transaction_type === 'Handover to Brand Owner' && $transaction->brand_owner_name) {
            $data['brandOwnerName'] = $transaction->brand_owner_name;
        }

        return $data;
    }
}
