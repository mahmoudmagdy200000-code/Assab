<?php

namespace Modules\Custody\Services;

use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Models\CashierShiftHandover;

class CashierCustodyService
{
    private const TRANSACTION_TYPE_HANDOVER_RECEIVED = 'Handover Received';

    private const TRANSACTION_TYPE_HANDOVER_SENT = 'Handover Sent';

    /**
     * Record a Cash-IN entry when a cashier accepts a handover from another cashier (shift handover flow).
     */
    public function recordHandoverReceived(CashierShiftHandover $handover, Cashier $receivingCashier): CashierCustodyTransaction
    {
        $existing = CashierCustodyTransaction::where('related_handover_id', $handover->id)
            ->where('cashier_id', $receivingCashier->id)
            ->where('transaction_type', self::TRANSACTION_TYPE_HANDOVER_RECEIVED)
            ->first();

        if ($existing) {
            return $existing;
        }

        $handover->loadMissing('cashierShift.cashier');
        $fromName = $handover->cashierShift?->cashier?->name ?? null;

        return CashierCustodyTransaction::create([
            'cashier_id' => $receivingCashier->id,
            'transaction_type' => self::TRANSACTION_TYPE_HANDOVER_RECEIVED,
            'amount' => $handover->handover_amount,
            'is_cash_in' => true,
            'counterpart_name' => $fromName,
            'related_shift_id' => $handover->cashier_shift_id,
            'related_handover_id' => $handover->id,
            'transaction_date' => now(),
        ]);
    }

    /**
     * Record a Cash-OUT entry when a cashier sends a handover to the next cashier/manager (shift handover flow).
     */
    public function recordHandoverSent(CashierShiftHandover $handover, Cashier $sendingCashier): CashierCustodyTransaction
    {
        $existing = CashierCustodyTransaction::where('related_handover_id', $handover->id)
            ->where('cashier_id', $sendingCashier->id)
            ->where('transaction_type', self::TRANSACTION_TYPE_HANDOVER_SENT)
            ->first();

        if ($existing) {
            return $existing;
        }

        $toName = $this->resolveRecipientName($handover);

        return CashierCustodyTransaction::create([
            'cashier_id' => $sendingCashier->id,
            'transaction_type' => self::TRANSACTION_TYPE_HANDOVER_SENT,
            'amount' => $handover->handover_amount,
            'is_cash_in' => false,
            'counterpart_name' => $toName,
            'related_shift_id' => $handover->cashier_shift_id,
            'related_handover_id' => $handover->id,
            'transaction_date' => now(),
        ]);
    }

    /**
     * Record Cash-IN (Total Sales) when a cashier sends a handover.
     * Amount = sum of shift payment methods: cash_collected + card_payments + aggregator (salesBreakdown).
     * Falls back to total_sales then handover_amount if sum is zero.
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

        $handover->loadMissing(['cashierShift', 'cashierShift.salesBreakdown']);
        $shift = $handover->cashierShift;
        $totalSales = 0.0;
        if ($shift) {
            $cashCollected = (float) ($shift->cash_collected ?? 0);
            $cardPayments = (float) ($shift->card_payments ?? 0);
            $aggregatorTotal = (float) $shift->salesBreakdown->sum('amount');
            $totalSales = $cashCollected + $cardPayments + $aggregatorTotal;
            if ($totalSales <= 0) {
                $totalSales = (float) ($shift->total_sales ?? 0);
            }
            if ($totalSales <= 0) {
                $totalSales = (float) $handover->handover_amount;
            }
        } else {
            $totalSales = (float) $handover->handover_amount;
        }

        $toName = $this->resolveRecipientName($handover);

        return CashierCustodyTransaction::create([
            'cashier_id' => $sendingCashier->id,
            'transaction_type' => 'Total Sales',
            'amount' => $totalSales,
            'is_cash_in' => true,
            'counterpart_name' => $toName,
            'related_shift_id' => $handover->cashier_shift_id,
            'related_handover_id' => $handover->id,
            'transaction_date' => now(),
        ]);
    }

    /**
     * Display name of who actually received the handover: the receiving cashier,
     * or — for manager handovers — the branch manager who approved (branch-wide
     * approval means the approver holds the cash, not necessarily the addressee).
     */
    private function resolveRecipientName(CashierShiftHandover $handover): ?string
    {
        if ($handover->handover_to_type === 'cashier') {
            return Cashier::find($handover->handover_to_id)?->name;
        }

        $receivingManagerId = $handover->receivingBranchManagerId();

        return $receivingManagerId
            ? \Modules\BranchManagers\Models\BranchManager::find($receivingManagerId)?->name
            : null;
    }

    /**
     * Record a Cash-OUT entry from the custody/handover endpoint (manual handover, not shift-based).
     */
    public function recordManualHandoverSent(string $cashierId, float $amount, ?string $recipientName): CashierCustodyTransaction
    {
        return CashierCustodyTransaction::create([
            'cashier_id' => $cashierId,
            'transaction_type' => self::TRANSACTION_TYPE_HANDOVER_SENT,
            'amount' => $amount,
            'is_cash_in' => false,
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
            'cashier_id' => $receivingCashierId,
            'transaction_type' => self::TRANSACTION_TYPE_HANDOVER_RECEIVED,
            'amount' => $amount,
            'is_cash_in' => true,
            'counterpart_name' => $senderName,
            'transaction_date' => now(),
        ]);
    }

    /**
     * Remove all custody rows tied to a cashier shift (end shift / handover / variance rollback).
     */
    public function deleteTransactionsForCashierShift(string $cashierShiftId): void
    {
        CashierCustodyTransaction::where('related_shift_id', $cashierShiftId)->delete();
    }

    /**
     * Get current personal balance (cash in - cash out) for a cashier (single aggregated query).
     */
    public function getPersonalBalanceOnly(string $cashierId): float
    {
        $balance = CashierCustodyTransaction::where('cashier_id', $cashierId)
            ->selectRaw('SUM(CASE WHEN is_cash_in = 1 THEN amount ELSE -amount END) as balance')
            ->value('balance');

        return round((float) ($balance ?? 0), 2);
    }

    /**
     * Get custody balance summary — same response shape as PersonalLedgerService::getPersonalCustodyBalance().
     */
    public function getPersonalCustodyBalance(string $cashierId, ?int $month = null, ?int $year = null): array
    {
        $query = CashierCustodyTransaction::where('cashier_id', $cashierId);

        if (! empty($month) && ! empty($year)) {
            $query->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();

        $totalCashIn = round((float) $transactions->where('is_cash_in', true)->sum('amount'), 2);
        $totalCashOut = round((float) $transactions->where('is_cash_in', false)->sum('amount'), 2);

        $recentActivity = $transactions->take(5)->map(fn ($t) => $this->formatForActivity($t))->values();

        return [
            'totalCashIn' => $totalCashIn,
            'totalCashOut' => $totalCashOut,
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

        if (! empty($filters['month']) && ! empty($filters['year'])) {
            $query->whereYear('transaction_date', (int) $filters['year'])
                ->whereMonth('transaction_date', (int) $filters['month']);
        }

        if (! empty($filters['transactionType'])) {
            $query->where('transaction_type', $filters['transactionType']);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();

        return [
            'view' => $view,
            'totalTransactions' => $transactions->count(),
            'transactions' => $transactions->map(fn ($t) => $this->formatForList($t))->values(),
        ];
    }

    // ── Private formatters (same keys as PersonalLedgerService) ──────────────

    private function formatForActivity(CashierCustodyTransaction $t): array
    {
        $sign = $t->is_cash_in ? '+' : '-';
        $amount = $sign.number_format((float) $t->amount, 2, '.', '');

        return [
            'transactionType' => $t->transaction_type,
            'amount' => $amount,
            'dateTime' => $t->transaction_date->toIso8601String(),
            'isCashIn' => $t->is_cash_in,
            'cashierName' => $t->counterpart_name,   // mirrors PersonalLedgerService key
        ];
    }

    private function formatForList(CashierCustodyTransaction $t): array
    {
        $sign = $t->is_cash_in ? '+' : '-';
        $amount = $sign.number_format((float) $t->amount, 2, '.', '');

        return [
            'id' => $t->id,
            'transactionType' => $t->transaction_type,
            'amount' => $amount,
            'dateTime' => $t->transaction_date->toIso8601String(),
            'cashierName' => $t->counterpart_name,   // mirrors PersonalLedgerService key
        ];
    }
}
