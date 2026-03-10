<?php

namespace Modules\Custody\Services;

use Illuminate\Support\Facades\Log;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Models\CashierShiftHandover;

class CashierCustodyService
{
    /**
     * Record a Cash-IN entry when a cashier accepts a handover from another cashier (shift handover flow).
     */
    public function recordHandoverReceived(CashierShiftHandover $handover, Cashier $receivingCashier): CashierCustodyTransaction
    {
        $existing = CashierCustodyTransaction::where('related_handover_id', $handover->id)
            ->where('cashier_id', $receivingCashier->id)
            ->where('transaction_type', 'Handover Received')
            ->first();

        if ($existing) {
            return $existing;
        }

        $handover->loadMissing('cashierShift.cashier');
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
     * Record a Cash-OUT entry when a cashier sends a handover to the next cashier/manager (shift handover flow).
     */
    public function recordHandoverSent(CashierShiftHandover $handover, Cashier $sendingCashier): CashierCustodyTransaction
    {
        $existing = CashierCustodyTransaction::where('related_handover_id', $handover->id)
            ->where('cashier_id', $sendingCashier->id)
            ->where('transaction_type', 'Handover Sent')
            ->first();

        if ($existing) {
            return $existing;
        }

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
     * Record Cash-IN (Total Sales) when a cashier sends a handover (declaring the cash they hold).
     * This entry stays if rejected, and is complemented by a Cash-OUT when accepted.
     */
    public function recordCashCollected(CashierShiftHandover $handover, Cashier $sendingCashier): CashierCustodyTransaction
    {
        $existing = CashierCustodyTransaction::where('related_handover_id', $handover->id)
            ->where('cashier_id', $sendingCashier->id)
            ->where('transaction_type', 'Total Sales')
            ->first();

        if ($existing) {
            return $existing;
        }

        $toName = null;
        if ($handover->handover_to_type === 'cashier') {
            $toName = Cashier::find($handover->handover_to_id)?->name;
        } elseif ($handover->handover_to_type === 'branch_manager') {
            $toName = \Modules\BranchManagers\Models\BranchManager::find($handover->handover_to_id)?->name;
        }

        return CashierCustodyTransaction::create([
            'cashier_id'          => $sendingCashier->id,
            'transaction_type'    => 'Total Sales',
            'amount'              => $handover->handover_amount,
            'is_cash_in'          => true,
            'counterpart_name'    => $toName,
            'related_shift_id'    => $handover->cashier_shift_id,
            'related_handover_id' => $handover->id,
            'transaction_date'    => now(),
        ]);
    }

    /**
     * Record a Cash-OUT entry from the custody/handover endpoint (manual handover, not shift-based).
     */
    public function recordManualHandoverSent(string $cashierId, float $amount, ?string $recipientName): CashierCustodyTransaction
    {
        return CashierCustodyTransaction::create([
            'cashier_id'       => $cashierId,
            'transaction_type' => 'Handover Sent',
            'amount'           => $amount,
            'is_cash_in'       => false,
            'counterpart_name' => $recipientName,
            'transaction_date' => now(),
        ]);
    }

    /**
     * Record Cash-IN (Handover Received) for a cashier when another cashier sends manual handover to them.
     */
    public function recordManualHandoverReceived(string $receivingCashierId, float $amount, ?string $senderName): CashierCustodyTransaction
    {
        return CashierCustodyTransaction::create([
            'cashier_id'       => $receivingCashierId,
            'transaction_type' => 'Handover Received',
            'amount'           => $amount,
            'is_cash_in'       => true,
            'counterpart_name' => $senderName,
            'transaction_date' => now(),
        ]);
    }

    /**
     * Get current personal balance (cash in - cash out) for a cashier.
     */
    public function getPersonalBalanceOnly(string $cashierId): float
    {
        $transactions = CashierCustodyTransaction::where('cashier_id', $cashierId)->get();

        return round(
            (float) $transactions->where('is_cash_in', true)->sum('amount')
            - (float) $transactions->where('is_cash_in', false)->sum('amount'),
            2
        );
    }

    /**
     * Get custody balance summary — same response shape as PersonalLedgerService::getPersonalCustodyBalance().
     */
    public function getPersonalCustodyBalance(string $cashierId, ?int $month = null, ?int $year = null): array
    {
        $query = CashierCustodyTransaction::where('cashier_id', $cashierId);

        if (!empty($month) && !empty($year)) {
            $query->whereYear('transaction_date', $year)
                  ->whereMonth('transaction_date', $month);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();

        $totalCashIn  = round((float) $transactions->where('is_cash_in', true)->sum('amount'), 2);
        $totalCashOut = round((float) $transactions->where('is_cash_in', false)->sum('amount'), 2);

        $recentActivity = $transactions->take(5)->map(fn ($t) => $this->formatForActivity($t))->values();

        return [
            'totalCashIn'    => $totalCashIn,
            'totalCashOut'   => $totalCashOut,
            'currentBalance' => round($totalCashIn - $totalCashOut, 2),
            'recentActivity' => $recentActivity,
        ];
    }

    /**
     * Get transaction history — same response shape as PersonalLedgerService::getTransactionHistory().
     */
    public function getTransactionHistory(string $cashierId, array $filters = []): array
    {
        $query = CashierCustodyTransaction::where('cashier_id', $cashierId);

        $view = $filters['view'] ?? 'detailed';
        if ($view === 'daily') {
            $query->whereDate('transaction_date', today());
        }

        if (!empty($filters['month']) && !empty($filters['year'])) {
            $query->whereYear('transaction_date', (int) $filters['year'])
                  ->whereMonth('transaction_date', (int) $filters['month']);
        }

        if (!empty($filters['transactionType'])) {
            $query->where('transaction_type', $filters['transactionType']);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();

        return [
            'view'              => $view,
            'totalTransactions' => $transactions->count(),
            'transactions'      => $transactions->map(fn ($t) => $this->formatForList($t))->values(),
        ];
    }

    // ── Private formatters (same keys as PersonalLedgerService) ──────────────

    private function formatForActivity(CashierCustodyTransaction $t): array
    {
        $sign   = $t->is_cash_in ? '+' : '-';
        $amount = $sign . number_format((float) $t->amount, 2, '.', '');

        return [
            'transactionType' => $t->transaction_type,
            'amount'          => $amount,
            'dateTime'        => $t->transaction_date->toIso8601String(),
            'isCashIn'        => $t->is_cash_in,
            'cashierName'     => $t->counterpart_name,   // mirrors PersonalLedgerService key
        ];
    }

    private function formatForList(CashierCustodyTransaction $t): array
    {
        $sign   = $t->is_cash_in ? '+' : '-';
        $amount = $sign . number_format((float) $t->amount, 2, '.', '');

        return [
            'id'              => $t->id,
            'transactionType' => $t->transaction_type,
            'amount'          => $amount,
            'dateTime'        => $t->transaction_date->toIso8601String(),
            'cashierName'     => $t->counterpart_name,   // mirrors PersonalLedgerService key
        ];
    }
}
