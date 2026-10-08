<?php

namespace Modules\Custody\Services;

use Modules\Custody\Enums\TransactionType;
use Modules\Custody\Models\PersonalLedgerTransaction;

class PersonalLedgerService
{
    /**
     * Get personal custody balance summary
     */
    public function getPersonalCustodyBalance(string $branchManagerId, ?int $month = null, ?int $year = null): array
    {
        $query = PersonalLedgerTransaction::where('branch_manager_id', $branchManagerId);

        // Apply month and year filter if provided
        if (! empty($month) && ! empty($year)) {
            $query->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();

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

        // Month and Year filter (for Personal Ledger Transactions)
        if (! empty($filters['month']) && ! empty($filters['year'])) {
            $month = (int) $filters['month'];
            $year = (int) $filters['year'];
            $query->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month);
        }

        // Time period filter (for PDF export)
        if (! empty($filters['timePeriod'])) {
            $startDate = $this->getTimePeriodStartDate($filters['timePeriod']);
            $query->where('transaction_date', '>=', $startDate);
        }

        // Transaction type filter
        if (! empty($filters['transactionType'])) {
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
     * Get start date based on time period
     */
    private function getTimePeriodStartDate(string $timePeriod): \Carbon\Carbon
    {
        return match ($timePeriod) {
            'last_24_hours' => now()->subHours(24),
            'last_7_days' => now()->subDays(7),
            'last_30_days' => now()->subDays(30),
            'last_90_days' => now()->subDays(90),
            'last_365_days' => now()->subDays(365),
            default => now()->subDays(30), // Default to last 30 days
        };
    }

    /**
     * Format transaction for activity list
     */
    private function formatTransactionForActivity(PersonalLedgerTransaction $transaction): array
    {
        $amount = $transaction->is_cash_in
            ? '+'.number_format($transaction->amount, 2, '.', '')
            : '-'.number_format($transaction->amount, 2, '.', '');

        $data = [
            'transactionType' => $transaction->transaction_type,
            'amount' => $amount,
            'dateTime' => $transaction->transaction_date->toIso8601String(),
            'isCashIn' => $transaction->is_cash_in,
        ];

        if (in_array($transaction->transaction_type, [TransactionType::TOTAL_SALES->value, TransactionType::HANDOVER_TO_CASHIER->value], true) && $transaction->cashier_name) {
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
            ? '+'.number_format($transaction->amount, 2, '.', '')
            : '-'.number_format($transaction->amount, 2, '.', '');

        $data = [
            'id' => $transaction->id,
            'transactionType' => $transaction->transaction_type,
            'amount' => $amount,
            'dateTime' => $transaction->transaction_date->toIso8601String(),
        ];

        if (in_array($transaction->transaction_type, [TransactionType::TOTAL_SALES->value, TransactionType::HANDOVER_TO_CASHIER->value], true) && $transaction->cashier_name) {
            $data['cashierName'] = $transaction->cashier_name;
        }

        if ($transaction->transaction_type === 'Handover to Brand Owner' && $transaction->brand_owner_name) {
            $data['brandOwnerName'] = $transaction->brand_owner_name;
        }

        return $data;
    }
}
