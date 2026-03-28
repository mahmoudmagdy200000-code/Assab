<?php

namespace Modules\Custody\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\VarianceType;
use Modules\Shift\Events\VarianceRecorded;

/**
 * When variance responsibility is approved, reflect in custody ledgers:
 * - SHORT: cashier cash out; OVER: cashier cash in
 * - Branch manager line mirrors net effect for approved cashier-assigned rows only
 */
class CreateCustodyLedgerEntriesForVariance implements ShouldQueue
{
    use InteractsWithQueue;

    public $afterCommit = true;
    public $tries = 3;

    public function backoff(): array
    {
        $jitter = random_int(1, 4);
        return [10 + $jitter, 30 + $jitter, 90 + $jitter];
    }

    public function handle(VarianceRecorded $event): void
    {
        $shift = $event->shift->fresh(['handover', 'varianceDetails.responsibleCashier']);

        if ($shift->varianceDetails->isEmpty()) {
            return;
        }

        $approvedWithCashier = $shift->varianceDetails->filter(
            fn ($d) => $d->responsible_cashier_id !== null && $d->responsibility_status === 'approved'
        );

        try {
            DB::beginTransaction();

            $this->removeExistingVarianceLedgerEntriesForShift($shift->id);

            if ($approvedWithCashier->isEmpty()) {
                DB::commit();

                return;
            }

            foreach ($approvedWithCashier as $detail) {
                $this->createCashierVarianceEntry($shift, $detail);
            }

            if ($shift->handover && $shift->handover->handover_to_type === 'branch_manager' && $shift->handover->handover_to_id) {
                $this->createBranchManagerVarianceEntry($shift, $approvedWithCashier);
            }

            DB::commit();

            Log::info('Custody ledger entries created for variance', [
                'cashier_shift_id' => $shift->id,
                'details_count' => $approvedWithCashier->count(),
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

        $varianceType = $detail->variance_type instanceof VarianceType
            ? $detail->variance_type
            : (VarianceType::tryFrom((string) $detail->variance_type) ?? VarianceType::SHORT);

        $isCashIn = $varianceType === VarianceType::OVER;

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
            'cashier_id' => $detail->responsible_cashier_id,
            'transaction_type' => 'Variance',
            'amount' => $amount,
            'is_cash_in' => $isCashIn,
            'counterpart_name' => $counterpartName,
            'related_shift_id' => $shift->id,
            'related_handover_id' => $handoverId,
            'transaction_date' => $transactionDate,
        ]);
    }

    private function createBranchManagerVarianceEntry($shift, $detailsApproved): void
    {
        $shortTotal = 0.0;
        $overTotal = 0.0;

        foreach ($detailsApproved as $d) {
            $amt = (float) $d->assigned_amount;
            if ($amt <= 0) {
                continue;
            }
            $varianceType = $d->variance_type instanceof VarianceType
                ? $d->variance_type
                : (VarianceType::tryFrom((string) $d->variance_type) ?? VarianceType::SHORT);
            if ($varianceType === VarianceType::SHORT) {
                $shortTotal += $amt;
            } else {
                $overTotal += $amt;
            }
        }

        $net = $shortTotal - $overTotal;
        if (abs($net) < 0.0001) {
            return;
        }

        $cashierNames = $detailsApproved
            ->map(fn ($d) => $d->responsibleCashier?->name)
            ->filter()
            ->unique()
            ->implode(', ');

        PersonalLedgerTransaction::create([
            'branch_manager_id' => $shift->handover->handover_to_id,
            'transaction_type' => 'Variance from Cashier',
            'amount' => abs($net),
            'is_cash_in' => $net > 0,
            'cashier_name' => $cashierNames ?: null,
            'related_shift_id' => $shift->id,
            'related_handover_id' => $shift->handover->id,
            'transaction_date' => $shift->handover->handover_time ?? now(),
        ]);
    }
}
