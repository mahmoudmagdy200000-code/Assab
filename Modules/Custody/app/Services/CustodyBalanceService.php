<?php

namespace Modules\Custody\Services;

use Illuminate\Support\Facades\DB;
use Modules\Custody\Models\CustodyTransaction;

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
}
