<?php

namespace Modules\Custody\Services;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Custody\Exceptions\SalesCashTransferRefusedException;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Services\ShiftCashAvailabilityService;
use Modules\Shift\Services\ShiftReportReviewService;
use Modules\Shift\Services\ShiftTransferReceiptService;

/**
 * BRC v3.4 F-04 — the manager's transfer of SALES CASH into the branch EXPENSE CUSTODY (FUND-01, FUND-02).
 *
 *  FUND-01  only cash whose SOURCE DAY is fully approved (accountant, then head of accounts) may be transferred.
 *  FUND-02  the ceiling is the cash the manager really holds: executed movements are already in the personal
 *           ledger (never deducted a second time), open manager->cashier requests are reservations deducted once,
 *           and an uncollected shortage is a claim, never cash.
 *
 * AS-IS (Candidate 3c85fd97) the controller compared the amount with the raw sum of EVERY personal-ledger row
 * (claims included), ignored reservations and checked no approval at all.
 *
 * Money: the ledgers store decimal SAR (two places); every comparison here is in whole halalas.
 * Authority: this service owns the personal-ledger "Transfer to Custody" cash-out and the custody "Cash Transfer"
 * cash-in; it posts both or neither, under the manager-cash-owner lock shared with the manager->cashier transfers.
 */
class SalesCashTransferService
{
    public function __construct(
        private readonly ShiftTransferReceiptService $receipts,
        private readonly ShiftReportReviewService $review,
        private readonly CustodyTransactionService $custody,
    ) {}

    /**
     * Cash the manager holds and cash among it that FUND-01 releases. Caller holds the transaction + owner lock.
     *
     * @return array{available:int, unapproved:int, transferable:int}
     */
    public function ceiling(string $managerId): array
    {
        $available = $this->receipts->availableManagerCashMinor($managerId);
        $unapproved = $this->cashFromUnapprovedDays($managerId);

        return [
            'available' => $available,
            'unapproved' => $unapproved,
            'transferable' => max(0, $available - $unapproved),
        ];
    }

    /**
     * Move cash from the manager's sales cash to the branch expense custody.
     *
     * @param  int|null  $amountHalalas  null = "transfer everything the rules release"
     * @return array{transferredHalalas:int, personalBalanceSar:float}
     *
     * @throws SalesCashTransferRefusedException nothing has been written
     */
    public function transfer(BranchManager $manager, ?int $amountHalalas): array
    {
        return DB::transaction(function () use ($manager, $amountHalalas): array {
            // One mutex for every movement of this manager's cash (shared with manager->cashier requests).
            $this->receipts->lockManagerCashOwner((string) $manager->id);

            $ceiling = $this->ceiling((string) $manager->id);
            $amount = $amountHalalas ?? $ceiling['transferable'];

            if ($amount <= 0 || $amount > $ceiling['transferable']) {
                // Approval is the binding constraint when the amount WOULD fit the cash in hand.
                $byApproval = $ceiling['unapproved'] > 0 && $amount <= $ceiling['available'];

                throw new SalesCashTransferRefusedException(
                    $byApproval ? SalesCashTransferRefusedException::SOURCE_DAY_NOT_FULLY_APPROVED : SalesCashTransferRefusedException::EXCEEDS_AVAILABLE_CASH,
                    $ceiling['available'],
                    $ceiling['unapproved'],
                    $ceiling['transferable'],
                );
            }

            $sar = number_format($amount / 100, 2, '.', '');

            $this->custody->createCashTransferTransaction([
                'branch_manager_id' => $manager->id,
                'branch_id' => $manager->branch_id,
                'amount' => $sar,
            ]);

            PersonalLedgerTransaction::create([
                'branch_manager_id' => $manager->id,
                'transaction_type' => 'Transfer to Custody',
                'amount' => $sar,
                'is_cash_in' => false,
                'transaction_date' => now(),
            ]);

            return ['transferredHalalas' => $amount, 'personalBalanceSar' => $this->personalBalanceSar((string) $manager->id)];
        });
    }

    /**
     * Sales-cash credits (and settlement credits) whose source day is NOT verifiably fully approved, in halalas.
     * A credit that names no source day, or a day that is not this manager's, cannot prove FUND-01 and is held back.
     */
    private function cashFromUnapprovedDays(string $managerId): int
    {
        $credits = PersonalLedgerTransaction::query()
            ->where('branch_manager_id', $managerId)
            ->where('is_cash_in', true)
            ->where(fn ($query) => $query->where('transaction_type', 'Total Sales')->orWhere('category', 'handover_settlement'))
            ->orderBy('id')
            ->get(['amount', 'related_shift_id']);

        $verdict = []; // day id => approved?
        $held = 0;
        foreach ($credits as $credit) {
            $dayId = $credit->related_shift_id !== null ? (string) $credit->related_shift_id : null;
            if ($dayId !== null && ! array_key_exists($dayId, $verdict)) {
                $isOwnDay = BranchManagerShift::query()->whereKey($dayId)->where('branch_manager_id', $managerId)->exists();
                $verdict[$dayId] = $isOwnDay && $this->review->dayFullyFinalApproved($dayId);
            }
            if ($dayId === null || ! $verdict[$dayId]) {
                $held = ShiftCashAvailabilityService::add($held, ShiftFinancialCalculator::storedSarToHalalas($credit->amount));
            }
        }

        return $held;
    }

    /** Raw ledger balance (all rows) as the clients display it today — informational, never a ceiling. */
    private function personalBalanceSar(string $managerId): float
    {
        $minor = PersonalLedgerTransaction::query()->where('branch_manager_id', $managerId)->get(['amount', 'is_cash_in'])
            ->reduce(fn (int $sum, PersonalLedgerTransaction $row) => ShiftCashAvailabilityService::add(
                $sum,
                ($row->is_cash_in ? 1 : -1) * ShiftFinancialCalculator::storedSarToHalalas($row->amount)
            ), 0);

        return round($minor / 100, 2);
    }
}
