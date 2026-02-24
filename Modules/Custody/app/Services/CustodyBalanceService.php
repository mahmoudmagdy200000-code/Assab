<?php

namespace Modules\Custody\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Custody\Models\CustodyRequest;
use Modules\Custody\Models\PersonalLedgerTransaction;

class CustodyBalanceService
{
    private const CUSTODY_TYPES = ['branch', 'personal'];

    private const BRANCH_CUSTODY_REQUEST_TYPES = ['Cash Transfer', 'Cash Handover', 'Bank Transfer'];

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
     * Get balance trends data for UI (Balance Trend Stats screen).
     * Filters: custodyType (branch|personal), month, year, granularity (daily|weekly|monthly).
     * Returns structure matching the UI 100%.
     */
    public function getBalanceTrends(string $branchManagerId, array $filters = []): array
    {
        $custodyType = $filters['custodyType'] ?? 'branch';
        if (!in_array($custodyType, self::CUSTODY_TYPES, true)) {
            $custodyType = 'branch';
        }

        $month = isset($filters['month']) ? (int) $filters['month'] : (int) now()->month;
        $year = isset($filters['year']) ? (int) $filters['year'] : (int) now()->year;
        $granularity = $filters['granularity'] ?? 'daily';
        if (!in_array($granularity, ['daily', 'weekly', 'monthly'], true)) {
            $granularity = 'daily';
        }

        $startDate = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

        $currentTransactions = $this->getTrendsTransactions($branchManagerId, $custodyType, $startDate, $endDate);
        $previousStart = $startDate->copy()->subMonth()->startOfMonth();
        $previousEnd = $previousStart->copy()->endOfMonth();
        $previousTransactions = $this->getTrendsTransactions($branchManagerId, $custodyType, $previousStart, $previousEnd);

        $currentTotals = $this->sumCustodyAndExpenses($currentTransactions, $custodyType);
        $previousTotals = $this->sumCustodyAndExpenses($previousTransactions, $custodyType);

        $custodyChange = round($currentTotals['custodyRequests'] - $previousTotals['custodyRequests'], 2);
        $expenseChange = round($currentTotals['expenses'] - $previousTotals['expenses'], 2);
        $custodyPercentage = $this->calculatePercentage($currentTotals['custodyRequests'], $previousTotals['custodyRequests']);
        $expensePercentage = $this->calculatePercentage($currentTotals['expenses'], $previousTotals['expenses']);

        $dataPoints = $this->aggregateDataPointsForTrends($currentTransactions, $granularity, $startDate, $endDate, $custodyType);

        $expenseChart = array_map(fn (array $p): float => (float) ($p['expenses'] ?? 0), $dataPoints);
        $custodyRequestChart = array_map(fn (array $p): float => (float) ($p['custodyRequests'] ?? 0), $dataPoints);

        if ($this->arrayIsAllZeros($expenseChart)) {
            $expenseChart = [];
        }
        if ($this->arrayIsAllZeros($custodyRequestChart)) {
            $custodyRequestChart = [];
        }

        $dataPoints = $this->filterDataPointsZeros($dataPoints);

        $currentBalance = $this->getCurrentBalance($branchManagerId, $custodyType);

        $periodLabel = $this->getPeriodLabel($granularity);

        return [
            'currentBalance' => round($currentBalance, 2),
            'custodyType' => $custodyType,
            'month' => $month,
            'year' => $year,
            'granularity' => $granularity,
            'showingDataFrom' => $startDate->toIso8601String(),
            'timelineLabel' => $startDate->format('F Y'),
            'totalCustodyRequests' => [
                'value' => round($currentTotals['custodyRequests'], 2),
                'changeAmount' => $custodyChange,
                'changePercentage' => $custodyPercentage,
                'description' => $this->getComparisonDescription($custodyChange, $custodyPercentage, true, $periodLabel),
            ],
            'totalExpense' => [
                'value' => round($currentTotals['expenses'], 2),
                'changeAmount' => $expenseChange,
                'changePercentage' => $expensePercentage,
                'description' => $this->getComparisonDescription($expenseChange, $expensePercentage, false, $periodLabel),
            ],
            'expenseChart' => $expenseChart,
            'custodyRequestChart' => $custodyRequestChart,
            // 'dataPoints' => $dataPoints,
        ];
    }

    /**
     * Get current custody balance for branch or personal (as of all transactions to date).
     */
    private function getCurrentBalance(string $branchManagerId, string $custodyType): float
    {
        if ($custodyType === 'personal') {
            $transactions = PersonalLedgerTransaction::where('branch_manager_id', $branchManagerId)->get();
            $cashIn = $transactions->where('is_cash_in', true)->sum('amount');
            $cashOut = $transactions->where('is_cash_in', false)->sum('amount');
            return (float) ($cashIn - $cashOut);
        }

        $query = CustodyTransaction::where('branch_manager_id', $branchManagerId);
        $branchId = auth()->user()->branch_id ?? null;
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }
        $transactions = $query->get();
        $balance = 0.0;
        foreach ($transactions as $transaction) {
            if ($transaction->is_cash_in) {
                $balance += (float) $transaction->amount;
            } else {
                $balance -= (float) $transaction->amount;
            }
        }
        return $balance;
    }

    /**
     * Load transactions for trends (branch or personal) in date range
     */
    private function getTrendsTransactions(string $branchManagerId, string $custodyType, Carbon $start, Carbon $end): Collection
    {
        if ($custodyType === 'personal') {
            return PersonalLedgerTransaction::where('branch_manager_id', $branchManagerId)
                ->whereBetween('transaction_date', [$start, $end])
                ->orderBy('transaction_date')
                ->get();
        }

        $branchId = auth()->user()->branch_id ?? null;
        $query = CustodyTransaction::where('branch_manager_id', $branchManagerId)
            ->whereBetween('transaction_date', [$start, $end]);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->orderBy('transaction_date')->get();
    }

    /**
     * Sum custody requests (money in) and expenses (money out) from collection
     */
    private function sumCustodyAndExpenses(Collection $transactions, string $custodyType): array
    {
        if ($custodyType === 'personal') {
            $custodyRequests = $transactions->where('is_cash_in', true)->sum('amount');
            $expenses = $transactions->where('is_cash_in', false)->sum('amount');
            return [
                'custodyRequests' => (float) $custodyRequests,
                'expenses' => (float) $expenses,
            ];
        }

        $custodyRequests = $transactions
            ->filter(fn($t) => in_array($t->type, self::BRANCH_CUSTODY_REQUEST_TYPES, true) && $t->is_cash_in)
            ->sum('amount');
        $expenses = $transactions
            ->filter(fn($t) => $t->type === 'Expenses Deduction' && !$t->is_cash_in)
            ->sum('amount');

        return [
            'custodyRequests' => (float) $custodyRequests,
            'expenses' => (float) $expenses,
        ];
    }

    /**
     * Aggregate data points by daily/weekly/monthly for the given range
     */
    private function aggregateDataPointsForTrends(Collection $transactions, string $granularity, Carbon $start, Carbon $end, string $custodyType): array
    {
        $dataPoints = [];
        $getCustodyAndExpenses = function (Collection $subset) use ($custodyType) {
            return $this->sumCustodyAndExpenses($subset, $custodyType);
        };

        if ($granularity === 'monthly') {
            $totals = $getCustodyAndExpenses($transactions);
            $dataPoints[] = [
                'timestamp' => $start->toIso8601String(),
                'label' => $start->format('F Y'),
                'custodyRequests' => (float) round($totals['custodyRequests'], 2),
                'expenses' => (float) round($totals['expenses'], 2),
            ];
            return $dataPoints;
        }

        if ($granularity === 'weekly') {
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $weekEnd = $cursor->copy()->endOfWeek();
                if ($weekEnd->gt($end)) {
                    $weekEnd = $end->copy();
                }
                $subset = $transactions->filter(function ($t) use ($cursor, $weekEnd) {
                    $d = $t->transaction_date;
                    return $d->gte($cursor) && $d->lte($weekEnd);
                });
                $totals = $getCustodyAndExpenses($subset);
                $dataPoints[] = [
                    'timestamp' => $cursor->toIso8601String(),
                    'label' => 'Week of ' . $cursor->format('M j'),
                    'custodyRequests' => (float) round($totals['custodyRequests'], 2),
                    'expenses' => (float) round($totals['expenses'], 2),
                ];
                $cursor->addWeek()->startOfWeek();
            }
            return $dataPoints;
        }

        // daily
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $subset = $transactions->filter(fn($t) => $t->transaction_date->isSameDay($cursor));
            $totals = $getCustodyAndExpenses($subset);
            $dataPoints[] = [
                'timestamp' => $cursor->toIso8601String(),
                'date' => $cursor->format('Y-m-d'),
                'custodyRequests' => (float) round($totals['custodyRequests'], 2),
                'expenses' => (float) round($totals['expenses'], 2),
            ];
            $cursor->addDay();
        }

        return $dataPoints;
    }

    /**
     * True if array has only zero (or empty).
     */
    private function arrayIsAllZeros(array $arr): bool
    {
        foreach ($arr as $v) {
            if ((float) $v != 0.0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Remove zero values from dataPoints: omit key when value is 0; drop points that have no non-zero data.
     */
    private function filterDataPointsZeros(array $dataPoints): array
    {
        $out = [];
        foreach ($dataPoints as $p) {
            $point = [];
            if (isset($p['timestamp'])) {
                $point['timestamp'] = $p['timestamp'];
            }
            if (isset($p['date'])) {
                $point['date'] = $p['date'];
            }
            if (isset($p['label'])) {
                $point['label'] = $p['label'];
            }
            $cr = (float) ($p['custodyRequests'] ?? 0);
            $ex = (float) ($p['expenses'] ?? 0);
            if ($cr != 0.0) {
                $point['custodyRequests'] = $cr;
            }
            if ($ex != 0.0) {
                $point['expenses'] = $ex;
            }
            if (isset($point['custodyRequests']) || isset($point['expenses'])) {
                $out[] = $point;
            }
        }
        return $out;
    }

    private function getPeriodLabel(string $granularity): string
    {
        return match ($granularity) {
            'daily' => 'LAST DAY',
            'weekly' => 'LAST WEEK',
            'monthly' => 'LAST MONTH',
            default => 'LAST PERIOD',
        };
    }

    private function getComparisonDescription(float $changeAmount, string $changePercentage, bool $isCustodyRequests, string $periodLabel): string
    {
        if ($isCustodyRequests) {
            if ($changeAmount > 0) {
                return 'INCREASE FROM ' . $periodLabel;
            }
            if ($changeAmount < 0) {
                return 'DECREASE FROM ' . $periodLabel;
            }
            return 'NO INCREASE FROM ' . $periodLabel;
        }

        if ($changeAmount > 0) {
            return 'HIGHER THAN ' . $periodLabel;
        }
        if ($changeAmount < 0) {
            return 'LOWER THAN ' . $periodLabel;
        }
        return 'SAME AS ' . $periodLabel;
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

        // Calculate branch balance (all branch managers in the branch)
        $branchTransactionsQuery = CustodyTransaction::where('branch_id', $branchId);

        // Apply time period filter to branch balance calculation
        if ($timePeriod === 'custom') {
            if (!empty($filters['startDate'])) {
                $branchTransactionsQuery->whereDate('transaction_date', '>=', $filters['startDate']);
            }
            if (!empty($filters['endDate'])) {
                $branchTransactionsQuery->whereDate('transaction_date', '<=', $filters['endDate']);
            }
        } elseif ($timePeriod) {
            $startDate = match($timePeriod) {
                'last_24_hours' => now()->subHours(24),
                'last_7_days' => now()->subDays(7),
                'last_30_days' => now()->subDays(30),
                default => null,
            };

            if ($startDate) {
                $branchTransactionsQuery->where('transaction_date', '>=', $startDate);
            }
        }

        $branchTransactions = $branchTransactionsQuery->get();
        $branchTotalCashIn = $branchTransactions->where('is_cash_in', true)->sum('amount');
        $branchTotalCashOut = $branchTransactions->where('is_cash_in', false)->sum('amount');
        $currentBalance = $branchTotalCashIn - $branchTotalCashOut;

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
            'currentBalance' => round($currentBalance, 2),
            'recentActivity' => $recentActivity,
            'requests' => $formattedRequests,
            'transactions' => $formattedTransactions,
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
