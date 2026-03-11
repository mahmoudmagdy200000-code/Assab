<?php

namespace Modules\Custody\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Events\VarianceRecorded;

/**
 * When a cashier is marked as variance owner, reflect in custody ledgers:
 * - Deduct from cashier's ledger (CashierCustodyTransaction, cash out)
 * - Add to branch manager's ledger (PersonalLedgerTransaction, cash in)
 */
class CreateCustodyLedgerEntriesForVariance
{

    public function handle(VarianceRecorded $event): void
    {
        $shift = $event->shift->fresh(['handover', 'varianceDetails.responsibleCashier']);

        if ($shift->varianceDetails->isEmpty()) {
            return;
        }

        $detailsWithCashier = $shift->varianceDetails->filter(fn ($d) => $d->responsible_cashier_id !== null);

        if ($detailsWithCashier->isEmpty()) {
            return;
        }

        try {
            DB::beginTransaction();

            $this->removeExistingVarianceLedgerEntriesForShift($shift->id);

            foreach ($detailsWithCashier as $detail) {
                $this->createCashierVarianceEntry($shift, $detail);
            }

            // Only create the branch manager entry when the handover to a manager exists
            if ($shift->handover && $shift->handover->handover_to_type === 'branch_manager' && $shift->handover->handover_to_id) {
                $this->createBranchManagerVarianceEntry($shift, $detailsWithCashier);
            }

            DB::commit();

            Log::info('Custody ledger entries created for variance', [
                'cashier_shift_id' => $shift->id,
                'details_count' => $detailsWithCashier->count(),
                'has_handover' => (bool) $shift->handover,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create custody ledger entries for variance', [
                'cashier_shift_id' => $shift->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function removeExistingVarianceLedgerEntriesForShift(string $cashierShiftId): void
    {
        CashierCustodyTransaction::where('related_shift_id', $cashierShiftId)
            ->where('transaction_type', 'Variance')
            ->delete();

        PersonalLedgerTransaction::where('related_shift_id', $cashierShiftId)
            ->where('transaction_type', 'Variance from Cashier')
            ->delete();
    }

    private function createCashierVarianceEntry($shift, $detail): void
    {
        $amount = (float) $detail->assigned_amount;
        if ($amount <= 0) {
            return;
        }

        $counterpartName = null;
        $handoverId = null;
        $transactionDate = now();

        if ($shift->handover) {
            $handoverId = $shift->handover->id;
            $transactionDate = $shift->handover->handover_time ?? now();
            if ($shift->handover->handover_to_type === 'branch_manager' && $shift->handover->handover_to_id) {
                $counterpartName = \Modules\BranchManagers\Models\BranchManager::find($shift->handover->handover_to_id)?->name;
            }
        }

        CashierCustodyTransaction::create([
            'cashier_id'          => $detail->responsible_cashier_id,
            'transaction_type'    => 'Variance',
            'amount'              => $amount,
            'is_cash_in'          => false,
            'counterpart_name'    => $counterpartName,
            'related_shift_id'    => $shift->id,
            'related_handover_id' => $handoverId,
            'transaction_date'    => $transactionDate,
        ]);
    }

    private function createBranchManagerVarianceEntry($shift, $detailsWithCashier): void
    {
        $totalAmount = $detailsWithCashier->sum(fn ($d) => (float) $d->assigned_amount);
        if ($totalAmount <= 0) {
            return;
        }

        $cashierNames = $detailsWithCashier->map(fn ($d) => $d->responsibleCashier?->name)->filter()->unique()->implode(', ');

        PersonalLedgerTransaction::create([
            'branch_manager_id' => $shift->handover->handover_to_id,
            'transaction_type' => 'Variance from Cashier',
            'amount' => $totalAmount,
            'is_cash_in' => true,
            'cashier_name' => $cashierNames ?: null,
            'related_shift_id' => $shift->id,
            'related_handover_id' => $shift->handover->id,
            'transaction_date' => $shift->handover->handover_time ?? now(),
        ]);
    }
}
