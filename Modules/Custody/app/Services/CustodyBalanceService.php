<?php

namespace Modules\Custody\Services;

use Illuminate\Support\Facades\DB;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Custody\Models\CustodyRequest;

class CustodyBalanceService
{
    /**
     * Get custody balance for branch manager
     */
    public function getCustodyBalance(string $branchManagerId): float
    {
        $transactions = CustodyTransaction::where('branch_manager_id', $branchManagerId)->get();

        $balance = 0;
        foreach ($transactions as $transaction) {
            if ($transaction->is_cash_in) {
                $balance += $transaction->amount;
            } else {
                $balance -= $transaction->amount;
            }
        }

        return round($balance, 2);
    }

    /**
     * Get balance trends data
     */
    public function getBalanceTrends(string $branchManagerId, array $filters = []): array
    {
        $period = $filters['period'] ?? 'today';
        $granularity = $filters['granularity'] ?? ($period === 'today' ? 'hourly' : 'daily');

        $query = CustodyTransaction::where('branch_manager_id', $branchManagerId);

        // Apply date filter based on period
        if ($period === 'today') {
            $query->whereDate('transaction_date', today());
        } elseif ($period === 'week') {
            $query->where('transaction_date', '>=', now()->subWeek());
        } elseif ($period === 'month') {
            $query->where('transaction_date', '>=', now()->subMonth());
        }

        $transactions = $query->get();

        $dataPoints = $this->aggregateDataPoints($transactions, $granularity);

        // Get current and previous period comparison
        $current = $this->getCurrentPeriodData($transactions, $granularity);
        $previous = $this->getPreviousPeriodData($branchManagerId, $period, $granularity);

        $comparison = $this->calculateComparison($current, $previous);

        return [
            'period' => $period,
            'granularity' => $granularity,
            'currentHour' => $current['hourly'] ?? null,
            'previousHour' => $previous['hourly'] ?? null,
            'comparison' => $comparison,
            'dataPoints' => $dataPoints,
        ];
    }

    /**
     * Aggregate data points by granularity
     */
    private function aggregateDataPoints($transactions, string $granularity): array
    {
        $dataPoints = [];

        if ($granularity === 'hourly') {
            for ($hour = 0; $hour < 24; $hour++) {
                $hourTransactions = $transactions->filter(function ($txn) use ($hour) {
                    return $txn->transaction_date->hour === $hour;
                });

                $custodyRequests = $hourTransactions
                    ->filter(fn($t) => in_array($t->type, ['Cash Transfer', 'Cash Handover', 'Bank Transfer']) && $t->is_cash_in)
                    ->sum('amount');

                $expenses = $hourTransactions
                    ->filter(fn($t) => $t->type === 'Expenses Deduction' && !$t->is_cash_in)
                    ->sum('amount');

                $dataPoints[] = [
                    'timestamp' => now()->setHour($hour)->setMinute(0)->setSecond(0)->toIso8601String(),
                    'hour' => str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00',
                    'custodyRequests' => round($custodyRequests, 2),
                    'expenses' => round($expenses, 2),
                ];
            }
        } else {
            // Daily aggregation
            $grouped = $transactions->groupBy(function ($txn) {
                return $txn->transaction_date->format('Y-m-d');
            });

            foreach ($grouped as $date => $dayTransactions) {
                $custodyRequests = $dayTransactions
                    ->filter(fn($t) => in_array($t->type, ['Cash Transfer', 'Cash Handover', 'Bank Transfer']) && $t->is_cash_in)
                    ->sum('amount');

                $expenses = $dayTransactions
                    ->filter(fn($t) => $t->type === 'Expenses Deduction' && !$t->is_cash_in)
                    ->sum('amount');

                $dataPoints[] = [
                    'timestamp' => $dayTransactions->first()->transaction_date->toIso8601String(),
                    'date' => $date,
                    'custodyRequests' => round($custodyRequests, 2),
                    'expenses' => round($expenses, 2),
                ];
            }
        }

        return $dataPoints;
    }

    /**
     * Get current period data
     */
    private function getCurrentPeriodData($transactions, string $granularity): array
    {
        $now = now();

        if ($granularity === 'hourly') {
            $currentHour = $transactions->filter(fn($t) => $t->transaction_date->hour === $now->hour);

            return [
                'hourly' => [
                    'hour' => $now->format('H:i'),
                    'custodyRequests' => [
                        'count' => $currentHour->filter(fn($t) => in_array($t->type, ['Cash Transfer', 'Cash Handover', 'Bank Transfer']) && $t->is_cash_in)->count(),
                        'total' => round($currentHour->filter(fn($t) => in_array($t->type, ['Cash Transfer', 'Cash Handover', 'Bank Transfer']) && $t->is_cash_in)->sum('amount'), 2),
                    ],
                    'expenses' => [
                        'count' => $currentHour->filter(fn($t) => $t->type === 'Expenses Deduction' && !$t->is_cash_in)->count(),
                        'total' => round($currentHour->filter(fn($t) => $t->type === 'Expenses Deduction' && !$t->is_cash_in)->sum('amount'), 2),
                    ],
                ],
            ];
        }

        return [];
    }

    /**
     * Get previous period data
     */
    private function getPreviousPeriodData(string $branchManagerId, string $period, string $granularity): array
    {
        $query = CustodyTransaction::where('branch_manager_id', $branchManagerId);

        if ($granularity === 'hourly') {
            $previousHour = now()->subHour();
            $query->where('transaction_date', '>=', $previousHour->copy()->startOfHour())
                ->where('transaction_date', '<', $previousHour->copy()->endOfHour());
        } else {
            // For daily, get previous day
            $query->whereDate('transaction_date', now()->subDay());
        }

        $transactions = $query->get();

        if ($granularity === 'hourly') {
            return [
                'hourly' => [
                    'hour' => $previousHour->format('H:i'),
                    'custodyRequests' => [
                        'count' => $transactions->filter(fn($t) => in_array($t->type, ['Cash Transfer', 'Cash Handover', 'Bank Transfer']) && $t->is_cash_in)->count(),
                        'total' => round($transactions->filter(fn($t) => in_array($t->type, ['Cash Transfer', 'Cash Handover', 'Bank Transfer']) && $t->is_cash_in)->sum('amount'), 2),
                    ],
                    'expenses' => [
                        'count' => $transactions->filter(fn($t) => $t->type === 'Expenses Deduction' && !$t->is_cash_in)->count(),
                        'total' => round($transactions->filter(fn($t) => $t->type === 'Expenses Deduction' && !$t->is_cash_in)->sum('amount'), 2),
                    ],
                ],
            ];
        }

        return [];
    }

    /**
     * Calculate comparison between periods
     */
    private function calculateComparison(array $current, array $previous): array
    {
        $currentHour = $current['hourly'] ?? null;
        $previousHour = $previous['hourly'] ?? null;

        if (!$currentHour || !$previousHour) {
            return [
                'custodyRequestsChange' => 0.00,
                'custodyRequestsPercentage' => '0%',
                'expensesChange' => 0.00,
                'expensesPercentage' => '0%',
            ];
        }

        $custodyRequestsChange = $currentHour['custodyRequests']['total'] - $previousHour['custodyRequests']['total'];
        $expensesChange = $currentHour['expenses']['total'] - $previousHour['expenses']['total'];

        $custodyRequestsPercentage = $this->calculatePercentage($currentHour['custodyRequests']['total'], $previousHour['custodyRequests']['total']);
        $expensesPercentage = $this->calculatePercentage($currentHour['expenses']['total'], $previousHour['expenses']['total']);

        return [
            'custodyRequestsChange' => round($custodyRequestsChange, 2),
            'custodyRequestsPercentage' => $custodyRequestsPercentage,
            'expensesChange' => round($expensesChange, 2),
            'expensesPercentage' => $expensesPercentage,
        ];
    }

    /**
     * Calculate percentage change
     */
    private function calculatePercentage(float $current, float $previous): string
    {
        if ($previous == 0) {
            return $current > 0 ? '+100%' : '0%';
        }

        $change = (($current - $previous) / $previous) * 100;
        $sign = $change >= 0 ? '+' : '';

        return $sign . round($change, 0) . '%';
    }

    /**
     * Get branch custody balance with requests and transactions
     * Similar to personal-custody-balance but for branch custody
     */
    public function getBranchCustodyBalance(string $branchManagerId, array $filters = []): array
    {
        $branchId = auth()->user()->branch_id;

        // Get all transactions for this branch manager
        $transactionsQuery = CustodyTransaction::where('branch_manager_id', $branchManagerId)
            ->where('branch_id', $branchId);

        // Apply transaction type filter
        if (!empty($filters['type'])) {
            $transactionsQuery->where('type', $filters['type']);
        }

        // Apply time period filter (last 24 hours, last 7 days, last 30 days, or custom)
        $timePeriod = $filters['timePeriod'] ?? null;
        if ($timePeriod === 'custom') {
            // Custom date range
            if (!empty($filters['startDate'])) {
                $transactionsQuery->whereDate('transaction_date', '>=', $filters['startDate']);
            }
            if (!empty($filters['endDate'])) {
                $transactionsQuery->whereDate('transaction_date', '<=', $filters['endDate']);
            }
        } elseif ($timePeriod) {
            $startDate = match($timePeriod) {
                'last_24_hours' => now()->subHours(24),
                'last_7_days' => now()->subDays(7),
                'last_30_days' => now()->subDays(30),
                default => null,
            };

            if ($startDate) {
                $transactionsQuery->where('transaction_date', '>=', $startDate);
            }
        }

        // Get status filter for requests
        $requestStatusFilter = $filters['status'] ?? null;

        // Get all requests for this branch manager
        $requestsQuery = CustodyRequest::where('branch_manager_id', $branchManagerId)
            ->where('branch_id', $branchId);

        // Apply status filter for requests
        if ($requestStatusFilter && $requestStatusFilter !== 'All') {
            if ($requestStatusFilter === 'Cash Handover' || $requestStatusFilter === 'Bank Transfer') {
                // Filter by preferred_receipt_method
                $requestsQuery->where('preferred_receipt_method', $requestStatusFilter);
            } elseif ($requestStatusFilter === 'Custody Requests') {
                // Show all requests regardless of status
                // No additional filter needed
            } else {
                // Filter by status
                $requestsQuery->where('status', $requestStatusFilter);
            }
        }

        // Apply time period filter to requests
        if ($timePeriod === 'custom') {
            // Custom date range
            if (!empty($filters['startDate'])) {
                $requestsQuery->whereDate('created_at', '>=', $filters['startDate']);
            }
            if (!empty($filters['endDate'])) {
                $requestsQuery->whereDate('created_at', '<=', $filters['endDate']);
            }
        } elseif ($timePeriod) {
            $startDate = match($timePeriod) {
                'last_24_hours' => now()->subHours(24),
                'last_7_days' => now()->subDays(7),
                'last_30_days' => now()->subDays(30),
                default => null,
            };

            if ($startDate) {
                $requestsQuery->where('created_at', '>=', $startDate);
            }
        }

        $transactions = $transactionsQuery->orderBy('transaction_date', 'desc')->get();
        $requests = $requestsQuery->orderBy('created_at', 'desc')->get();

        // Calculate balance
        $totalCashIn = $transactions->where('is_cash_in', true)->sum('amount');
        $totalCashOut = $transactions->where('is_cash_in', false)->sum('amount');
        $currentBalance = $totalCashIn - $totalCashOut;

        // Format transactions
        $formattedTransactions = $transactions->map(function ($transaction) {
            return $this->formatTransactionForBalance($transaction);
        })->values();

        // Format requests
        $formattedRequests = $requests->map(function ($request) {
            return $this->formatRequestForBalance($request);
        })->values();

        // Get recent activity (last 5 transactions)
        $recentActivity = $transactions->take(5)->map(function ($transaction) {
            return $this->formatTransactionForActivity($transaction);
        })->values();

        return [
            'totalCashIn' => round($totalCashIn, 2),
            'totalCashOut' => round($totalCashOut, 2),
            'currentBalance' => round($currentBalance, 2),
            'recentActivity' => $recentActivity,
            'requests' => $formattedRequests,
            'transactions' => $formattedTransactions,
            'filters' => [
                'type' => $filters['type'] ?? null,
                'status' => $filters['status'] ?? null,
                'timePeriod' => $filters['timePeriod'] ?? null,
                'startDate' => $filters['startDate'] ?? null,
                'endDate' => $filters['endDate'] ?? null,
            ],
        ];
    }

    /**
     * Format transaction for balance view
     */
    private function formatTransactionForBalance(CustodyTransaction $transaction): array
    {
        $amount = $transaction->is_cash_in
            ? '+' . number_format($transaction->amount, 2, '.', '')
            : '-' . number_format($transaction->amount, 2, '.', '');

        $data = [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'amount' => $amount,
            'dateTime' => $transaction->transaction_date->toIso8601String(),
            'isCashIn' => $transaction->is_cash_in,
        ];

        if ($transaction->type === 'Expenses Deduction' && $transaction->related_expense_id) {
            $data['linkedExpenseId'] = $transaction->related_expense_id;
        }

        if ($transaction->type === 'Cash Handover' || $transaction->type === 'Bank Transfer') {
            if ($transaction->related_custody_request_id) {
                $data['linkedRequestId'] = $transaction->related_custody_request_id;
            }
        }

        return $data;
    }

    /**
     * Format request for balance view
     */
    private function formatRequestForBalance(CustodyRequest $request): array
    {
        return [
            'id' => $request->id,
            'type' => 'Custody Request',
            'submittedBy' => 'Me (Branch Manager)',
            'dateTime' => $request->created_at->toIso8601String(),
            'status' => $request->status,
            'amount' => (float) $request->requested_amount,
            'preferredReceiptMethod' => $request->preferred_receipt_method,
            'purpose' => $request->purpose,
        ];
    }

    /**
     * Format transaction for activity list
     */
    private function formatTransactionForActivity(CustodyTransaction $transaction): array
    {
        $amount = $transaction->is_cash_in
            ? '+' . number_format($transaction->amount, 2, '.', '')
            : '-' . number_format($transaction->amount, 2, '.', '');

        $data = [
            'transactionType' => $transaction->type,
            'amount' => $amount,
            'dateTime' => $transaction->transaction_date->toIso8601String(),
            'isCashIn' => $transaction->is_cash_in,
        ];

        if ($transaction->type === 'Expenses Deduction' && $transaction->related_expense_id) {
            $data['linkedExpenseId'] = $transaction->related_expense_id;
        }

        return $data;
    }
}
