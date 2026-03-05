<?php

namespace Modules\Custody\Services;

use Illuminate\Support\Facades\Log;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Models\CashierShiftHandover;

class CashierCustodyService
{
    /**
     * Record a Cash-IN entry when a cashier accepts a handover from another cashier.
     */
    public function recordHandoverReceived(CashierShiftHandover $handover, Cashier $receivingCashier): CashierCustodyTransaction
    {
        $fromName = $handover->cashierShift?->cashier?->name ?? null;

        return CashierCustodyTransaction::create([
            'cashier_id'          => $receivingCashier->id,
            'transaction_type'    => 'Handover Received',
            'amount'              => $handover->handover_amount,
            'is_cash_in'          => true,
            'counterpart_name'    => $fromName,
            'related_shift_id'    => $handover->cashier_shift_id,
            'related_handover_id' => $handover->id,
            'transaction_date'    => now(),
        ]);
    }

    /**
     * Record a Cash-OUT entry when a cashier sends a handover to the next cashier or branch manager.
     */
    public function recordHandoverSent(CashierShiftHandover $handover, Cashier $sendingCashier): CashierCustodyTransaction
    {
        $toName = null;
        if ($handover->handover_to_type === 'cashier') {
            $toName = Cashier::find($handover->handover_to_id)?->name;
        } elseif ($handover->handover_to_type === 'branch_manager') {
            $toName = \Modules\BranchManagers\Models\BranchManager::find($handover->handover_to_id)?->name;
        }

        return CashierCustodyTransaction::create([
            'cashier_id'          => $sendingCashier->id,
            'transaction_type'    => 'Handover Sent',
            'amount'              => $handover->handover_amount,
            'is_cash_in'          => false,
            'counterpart_name'    => $toName,
            'related_shift_id'    => $handover->cashier_shift_id,
            'related_handover_id' => $handover->id,
            'transaction_date'    => now(),
        ]);
    }

    /**
     * Get custody balance summary for a cashier (with optional month/year filter).
     */
    public function getBalance(string $cashierId, ?int $month = null, ?int $year = null): array
    {
        $query = CashierCustodyTransaction::where('cashier_id', $cashierId);

        if (!empty($month) && !empty($year)) {
            $query->whereYear('transaction_date', $year)
                  ->whereMonth('transaction_date', $month);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();

        $totalCashIn  = (float) $transactions->where('is_cash_in', true)->sum('amount');
        $totalCashOut = (float) $transactions->where('is_cash_in', false)->sum('amount');

        $recentActivity = $transactions->take(5)->map(fn ($t) => $this->formatForActivity($t))->values();

        return [
            'totalCashIn'     => round($totalCashIn, 2),
            'totalCashOut'    => round($totalCashOut, 2),
            'currentBalance'  => round($totalCashIn - $totalCashOut, 2),
            'recentActivity'  => $recentActivity,
        ];
    }

    /**
     * Get paginated transaction list for a cashier.
     */
    public function getTransactions(string $cashierId, array $filters = []): array
    {
        $query = CashierCustodyTransaction::where('cashier_id', $cashierId);

        if (!empty($filters['month']) && !empty($filters['year'])) {
            $query->whereYear('transaction_date', (int) $filters['year'])
                  ->whereMonth('transaction_date', (int) $filters['month']);
        }

        if (!empty($filters['transaction_type'])) {
            $query->where('transaction_type', $filters['transaction_type']);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();

        return [
            'totalTransactions' => $transactions->count(),
            'transactions'      => $transactions->map(fn ($t) => $this->formatForList($t))->values(),
        ];
    }

    private function formatForActivity(CashierCustodyTransaction $t): array
    {
        $amount = $t->is_cash_in
            ? '+' . number_format((float) $t->amount, 2, '.', '')
            : '-' . number_format((float) $t->amount, 2, '.', '');

        return [
            'transactionType'  => $t->transaction_type,
            'amount'           => $amount,
            'counterpartName'  => $t->counterpart_name,
            'dateTime'         => $t->transaction_date->toIso8601String(),
            'isCashIn'         => $t->is_cash_in,
        ];
    }

    private function formatForList(CashierCustodyTransaction $t): array
    {
        $amount = $t->is_cash_in
            ? '+' . number_format((float) $t->amount, 2, '.', '')
            : '-' . number_format((float) $t->amount, 2, '.', '');

        return [
            'id'               => $t->id,
            'transactionType'  => $t->transaction_type,
            'amount'           => $amount,
            'counterpartName'  => $t->counterpart_name,
            'dateTime'         => $t->transaction_date->toIso8601String(),
            'isCashIn'         => $t->is_cash_in,
            'relatedShiftId'   => $t->related_shift_id,
        ];
    }
}
