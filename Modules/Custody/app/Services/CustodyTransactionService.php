<?php

namespace Modules\Custody\Services;

use Illuminate\Support\Facades\DB;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Custody\Models\CustodyRequest;

class CustodyTransactionService
{
    /**
     * List custody transactions with filters
     */
    public function listTransactions(string $branchManagerId, array $filters = []): array
    {
        $query = CustodyTransaction::where('branch_manager_id', $branchManagerId);

        // Type filter
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Date range filter
        if (!empty($filters['startDate'])) {
            $query->whereDate('transaction_date', '>=', $filters['startDate']);
        }
        if (!empty($filters['endDate'])) {
            $query->whereDate('transaction_date', '<=', $filters['endDate']);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();

        return [
            'transactions' => $transactions->map(function ($transaction) {
                return $this->formatTransaction($transaction);
            })->values(),
        ];
    }

    /**
     * Create transaction from approved custody request
     */
    public function createTransactionFromRequest(CustodyRequest $request): CustodyTransaction
    {
        return DB::transaction(function () use ($request) {
            return CustodyTransaction::create([
                'branch_manager_id' => $request->branch_manager_id,
                'branch_id' => $request->branch_id,
                'type' => $request->preferred_receipt_method === 'Cash Handover'
                    ? 'Cash Handover'
                    : 'Bank Transfer',
                'amount' => $request->requested_amount,
                'is_cash_in' => true,
                'related_custody_request_id' => $request->id,
                'transaction_date' => now(),
            ]);
        });
    }

    /**
     * Create transaction from expense deduction
     */
    public function createExpenseDeductionTransaction(array $data): CustodyTransaction
    {
        return DB::transaction(function () use ($data) {
            return CustodyTransaction::create([
                'branch_manager_id' => $data['branch_manager_id'],
                'branch_id' => $data['branch_id'],
                'type' => 'Expenses Deduction',
                'amount' => $data['amount'],
                'is_cash_in' => false,
                'related_expense_id' => $data['expense_id'],
                'transaction_date' => now(),
            ]);
        });
    }

    /**
     * Create cash transfer transaction
     */
    public function createCashTransferTransaction(array $data): CustodyTransaction
    {
        return DB::transaction(function () use ($data) {
            return CustodyTransaction::create([
                'branch_manager_id' => $data['branch_manager_id'],
                'branch_id' => $data['branch_id'],
                'type' => 'Cash Transfer',
                'amount' => $data['amount'],
                'is_cash_in' => true,
                'transaction_date' => now(),
            ]);
        });
    }

    /**
     * Create handover transaction
     */
    public function createHandoverTransaction(array $data): CustodyTransaction
    {
        return DB::transaction(function () use ($data) {
            return CustodyTransaction::create([
                'branch_manager_id' => $data['branch_manager_id'],
                'branch_id' => $data['branch_id'],
                'type' => 'Cash Handover',
                'amount' => $data['amount'],
                'is_cash_in' => false,
                'handover_recipient_type' => $data['recipient_type'],
                'handover_recipient_id' => $data['recipient_id'],
                'handover_method' => $data['handover_method'] ?? null,
                'handover_date' => $data['handover_date'] ?? null,
                'handover_notes' => $data['additional_notes'] ?? null,
                'transaction_date' => now(),
            ]);
        });
    }

    /**
     * Format transaction for API response
     */
    private function formatTransaction(CustodyTransaction $transaction): array
    {
        $amount = $transaction->is_cash_in
            ? '+' . number_format($transaction->amount, 2, '.', '')
            : '-' . number_format($transaction->amount, 2, '.', '');

        $data = [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'submittedBy' => 'Me',
            'dateTime' => $transaction->transaction_date->toIso8601String(),
            'amount' => $amount,
            'isCashIn' => $transaction->is_cash_in,
        ];

        if ($transaction->type === 'Expenses Deduction' && $transaction->related_expense_id) {
            $data['linkedExpenseId'] = $transaction->related_expense_id;
        }

        return $data;
    }
}
