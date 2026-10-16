<?php

namespace Modules\Shift\Services;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Models\ShiftTransferRejectionEvidence;

/**
 * S1-10: the single writer/reader of physical-count evidence, in integer halalas.
 *
 * - The confirmed opening is the sum of confirmed receipts into the shift (D2); configured opening,
 *   pending requests and the legacy opening_balance column are never inputs.
 * - Pending incoming (D11) is the physical amount a recipient counted when rejecting a request for
 *   correction, still owned by the sender, linked to that request; it is never surplus.
 * - Pending incoming (CASH-08, BRC v3.4) also covers cash physically in the drawer for an inbound request that
 *   is neither confirmed nor rejected. A pending request alone never proves physical possession: the counting
 *   cashier DECLARES the amount (`pending_incoming_cash`), and the server accepts it only up to the undecided
 *   requests actually addressed to this shift. It stays owned by the sender and is never surplus or shortage.
 * - expected = gross − cards − apps + confirmedOpening; variance = (counted − pendingIncoming) − expected.
 */
class ShiftCashCountService
{
    /** Sum of confirmed receipts into this cashier shift, in halalas (0 when none). */
    public function confirmedOpeningHalalas(string $cashierShiftId): int
    {
        $total = 0;
        foreach (DB::table('cashier_shift_handover_receipts')
            ->where('receiving_cashier_shift_id', $cashierShiftId)
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())
            ->pluck('confirmed_amount') as $amount) {
            $total += ShiftFinancialCalculator::storedSarToHalalas($amount);
        }

        return $total;
    }

    /**
     * D-11 part of pending incoming: physical cash counted by this shift's cashier at a
     * rejection-for-correction, not yet confirmed as a receipt. Only the latest evidence per request counts,
     * and a request that has since been confirmed contributes through the confirmed opening instead.
     * It is derived from stored rejection evidence only; see `undecidedInboundCapHalalas` for the CASH-08 part.
     */
    public function pendingIncomingHalalas(CashierShift $shift): int
    {
        $since = $shift->actual_start_time;
        $branchId = DB::table('shifts')->where('id', $shift->shift_id)->value('branch_id');

        $rows = ShiftTransferRejectionEvidence::query()
            ->where(function ($query) use ($shift, $since) {
                $query->where('receiving_cashier_shift_id', $shift->id)
                    ->orWhere(function ($unattributed) use ($shift, $since) {
                        $unattributed->whereNull('receiving_cashier_shift_id')
                            ->where('recipient_type', 'cashier')
                            ->where('recipient_id', $shift->cashier_id);
                        if ($since !== null) {
                            // D19: only a shift that started at or after the rejection can own it.
                            $unattributed->where('rejected_at', '<=', $since);
                        }
                    });
            })
            ->orderBy('rejected_at')
            ->orderBy('created_at')
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->get()
            ->filter(fn ($row) => $row->receiving_cashier_shift_id !== null
                || $this->ownsUnattributedEvidence($shift, $row, $branchId, $since));

        $latest = [];
        foreach ($rows as $row) {
            $key = $row->transfer_attempt_id ? 'a:'.$row->transfer_attempt_id : ($row->cashier_shift_handover_id
                ? 'h:'.$row->cashier_shift_handover_id
                : 't:'.$row->branch_manager_cash_transfer_id);
            $latest[$key] = $row;
        }

        $total = 0;
        foreach ($latest as $row) {
            if ($row->transfer_attempt_id) {
                $confirmed = DB::table('cashier_shift_handover_receipts')->where('transfer_attempt_id', $row->transfer_attempt_id)
                    ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first(['id']) !== null;
                if (! $confirmed) {
                    $returned = (int) DB::table('shift_transfer_returns')->where('transfer_attempt_id', $row->transfer_attempt_id)->whereNotNull('sender_confirmed_at')
                        ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get(['returned_halalas'])->sum('returned_halalas');
                    $total += max(0, $row->physical_halalas - $returned);
                }

                continue;
            }
            $confirmed = DB::table('cashier_shift_handover_receipts')
                ->when($row->cashier_shift_handover_id, fn ($q) => $q->where('cashier_shift_handover_id', $row->cashier_shift_handover_id))
                ->when($row->branch_manager_cash_transfer_id, fn ($q) => $q->where('branch_manager_cash_transfer_id', $row->branch_manager_cash_transfer_id))
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first(['id']) !== null;
            if (! $confirmed) {
                $total += $row->physical_halalas;
            }
        }

        return $total;
    }

    /**
     * D19: evidence recorded with no receiving shift belongs to exactly one shift: the recipient's first
     * shift in the same branch that started at or after the rejection. It is never subtracted twice.
     */
    private function ownsUnattributedEvidence(CashierShift $shift, ShiftTransferRejectionEvidence $row, mixed $branchId, mixed $since): bool
    {
        if ($branchId === null || $since === null) {
            return false;
        }
        $sourceBranch = $row->transfer_attempt_id
            ? DB::table('shift_transfer_attempts')->where('id', $row->transfer_attempt_id)->value('source_branch_id')
            : ($row->cashier_shift_handover_id
            ? DB::table('cashier_shift_handovers as h')
                ->join('cashier_shifts as c', 'c.id', '=', 'h.cashier_shift_id')
                ->join('shifts as s', 's.id', '=', 'c.shift_id')
                ->where('h.id', $row->cashier_shift_handover_id)->value('s.branch_id')
            : DB::table('branch_manager_cash_transfers as t')
                ->join('branch_manager_shifts as m', 'm.id', '=', 't.branch_manager_shift_id')
                ->where('t.id', $row->branch_manager_cash_transfer_id)->value('m.branch_id'));
        if ($sourceBranch === null || (string) $sourceBranch !== (string) $branchId) {
            return false;
        }

        $earlier = DB::table('cashier_shifts')
            ->join('shifts', 'shifts.id', '=', 'cashier_shifts.shift_id')
            ->where('cashier_shifts.cashier_id', $row->recipient_id)
            ->where('shifts.branch_id', $branchId)
            ->where('cashier_shifts.id', '!=', $shift->id)
            ->whereNotNull('cashier_shifts.actual_start_time')
            ->where('cashier_shifts.actual_start_time', '>=', $row->rejected_at)
            ->where(function ($query) use ($since, $shift) {
                $query->where('cashier_shifts.actual_start_time', '<', $since)
                    ->orWhere(function ($tie) use ($since, $shift) {
                        $tie->where('cashier_shifts.actual_start_time', $since)
                            ->where('cashier_shifts.id', '<', $shift->id);
                    });
            })
            ->exists();

        return ! $earlier;
    }

    /**
     * CASH-08 ceiling: the sum of the requests that are still UNDECIDED (pending, no receipt, not cancelled or
     * superseded, no rejection evidence on the current attempt) and are addressed to this cashier shift. The
     * recipient may declare at most this much of the counted drawer as undecided inbound cash.
     */
    public function undecidedInboundCapHalalas(CashierShift $shift): int
    {
        return array_sum($this->undecidedInboundRequests($shift));
    }

    /**
     * @return array<string,int> request key (`h:<id>` handover, `t:<id>` manager transfer) => requested halalas
     */
    private function undecidedInboundRequests(CashierShift $shift): array
    {
        if ($shift->actual_start_time === null) {
            return [];
        }
        $branchId = DB::table('shifts')->where('id', $shift->shift_id)->value('branch_id');
        $lock = DB::transactionLevel() > 0;
        $caps = [];

        $handoverIds = DB::table('cashier_shift_handovers as h')
            ->join('cashier_shifts as src', 'src.id', '=', 'h.cashier_shift_id')
            ->join('shifts as sh', 'sh.id', '=', 'src.shift_id')
            ->where('h.handover_to_type', 'cashier')
            ->where('h.handover_to_id', $shift->cashier_id)
            ->where('h.status', 'pending')
            ->whereNull('h.cancelled_at')
            ->whereNull('h.superseded_at')
            ->where('sh.branch_id', $branchId)
            ->where('src.id', '!=', $shift->id)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('cashier_shift_handover_receipts as r')->whereColumn('r.cashier_shift_handover_id', 'h.id'))
            ->orderBy('h.id')
            ->pluck('h.id');
        // The joined tables are only read; the locking read touches the request rows alone.
        $handovers = $handoverIds->isEmpty() ? collect() : DB::table('cashier_shift_handovers')->whereIn('id', $handoverIds->all())
            ->where('status', 'pending')->whereNull('cancelled_at')->whereNull('superseded_at')
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'handover_amount', 'current_transfer_attempt_id', 'created_at']);
        foreach ($handovers as $row) {
            $cap = $this->undecidedCap($shift, $branchId, 'cashier_shift_handover_id', $row->id, $row->current_transfer_attempt_id,
                ShiftFinancialCalculator::storedSarToHalalas($row->handover_amount), $row->created_at, false);
            if ($cap !== null) {
                $caps['h:'.$row->id] = $cap;
            }
        }

        $transfers = DB::table('branch_manager_cash_transfers as t')
            ->where('t.destination_cashier_shift_id', $shift->id)
            ->where('t.destination_cashier_id', $shift->cashier_id)
            ->where('t.status', 'pending')
            ->whereNull('t.cancelled_at')
            ->whereNull('t.superseded_at')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('cashier_shift_handover_receipts as r')->whereColumn('r.branch_manager_cash_transfer_id', 't.id'))
            ->orderBy('t.id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['t.id', 't.requested_amount', 't.current_transfer_attempt_id', 't.created_at']);
        foreach ($transfers as $row) {
            // A manager transfer names its receiving shift explicitly, so this shift is its only owner.
            $cap = $this->undecidedCap($shift, $branchId, 'branch_manager_cash_transfer_id', $row->id, $row->current_transfer_attempt_id,
                ShiftFinancialCalculator::storedSarToHalalas($row->requested_amount), $row->created_at, true);
            if ($cap !== null) {
                $caps['t:'.$row->id] = $cap;
            }
        }

        return $caps;
    }

    /**
     * The undecided amount one request can contribute to this shift, or null when it contributes nothing:
     * its current attempt already has rejection evidence (D-11 covers that physical cash), a legacy request
     * has rejection history without attempt identity (never relabelled), the attempt names another receiving
     * shift, or this shift is not the request's single owner (no attribution to two shifts).
     */
    private function undecidedCap(CashierShift $shift, mixed $branchId, string $evidenceColumn, string $requestId, ?string $attemptId, int $requestedHalalas, mixed $requestCreatedAt, bool $explicitReceivingShift): ?int
    {
        $lock = DB::transactionLevel() > 0;
        $cap = $requestedHalalas;
        if ($attemptId !== null) {
            $attempt = DB::table('shift_transfer_attempts')->where('id', $attemptId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
            if ($attempt === null || DB::table('shift_transfer_rejection_evidence')->where('transfer_attempt_id', $attemptId)->exists()) {
                return null;
            }
            if ($attempt->receiving_cashier_shift_id !== null) {
                if ((string) $attempt->receiving_cashier_shift_id !== (string) $shift->id) {
                    return null;
                }
                $explicitReceivingShift = true;
            }
            $cap = (int) $attempt->presented_halalas;
        } elseif (DB::table('shift_transfer_rejection_evidence')->where($evidenceColumn, $requestId)->exists()) {
            return null;
        }
        if ($cap <= 0 || (! $explicitReceivingShift && ! $this->isSingleOwnerShift($shift, $branchId, $requestCreatedAt))) {
            return null;
        }

        return $cap;
    }

    /**
     * A request that names no receiving shift belongs to exactly one shift: the recipient's earliest started
     * shift in the branch that was still running when the request was created or started later. Later shifts
     * of the same cashier can never claim it, so one request is never attributed twice.
     */
    private function isSingleOwnerShift(CashierShift $shift, mixed $branchId, mixed $requestCreatedAt): bool
    {
        $owner = DB::table('cashier_shifts')
            ->join('shifts', 'shifts.id', '=', 'cashier_shifts.shift_id')
            ->where('cashier_shifts.cashier_id', $shift->cashier_id)
            ->where('shifts.branch_id', $branchId)
            ->whereNotNull('cashier_shifts.actual_start_time')
            ->whereNotIn('cashier_shifts.status', [ShiftStatus::CANCELED->value, ShiftStatus::REASSIGNED->value])
            ->where(fn ($query) => $query->whereNull('cashier_shifts.actual_end_time')->orWhere('cashier_shifts.actual_end_time', '>=', $requestCreatedAt))
            ->orderBy('cashier_shifts.actual_start_time')
            ->orderBy('cashier_shifts.id')
            ->value('cashier_shifts.id');

        return $owner !== null && (string) $owner === (string) $shift->id;
    }

    /** Validates the cashier's declaration against the undecided requests; returns the accepted halalas. */
    private function acceptedUndecidedDeclaration(CashierShift $shift, int $declaredHalalas): int
    {
        if ($declaredHalalas <= 0) {
            return 0;
        }
        $cap = $this->undecidedInboundCapHalalas($shift);
        if ($declaredHalalas > $cap) {
            throw ValidationException::withMessages([
                'pending_incoming_cash' => $cap === 0
                    ? 'No undecided incoming transfer is addressed to this shift.'
                    : 'The declared pending incoming cash exceeds the undecided incoming transfers addressed to this shift.',
            ]);
        }

        return $declaredHalalas;
    }

    /**
     * The pure calculation shared by the persisted count and the read-only preview. It writes nothing.
     *
     * @return array{0:int,1:int,2:array<string,mixed>} confirmed opening, pending incoming, calculator result (halalas)
     */
    private function compute(CashierShift $shift, int $grossHalalas, int $cardsHalalas, int $appsHalalas, int $countedHalalas, int $declaredPendingHalalas = 0): array
    {
        $channels = ShiftFinancialCalculator::salesChannelCheck($grossHalalas, $cardsHalalas, $appsHalalas);
        if (! $channels['channelsValid']) {
            throw ValidationException::withMessages([
                'card_payments' => 'Card payments plus delivery-app sales cannot exceed gross sales.',
            ]);
        }

        $opening = $this->confirmedOpeningHalalas($shift->id);
        $pending = $this->pendingIncomingHalalas($shift) + $this->acceptedUndecidedDeclaration($shift, $declaredPendingHalalas);

        try {
            $result = ShiftFinancialCalculator::calculate($grossHalalas, $cardsHalalas, $appsHalalas, $opening, $countedHalalas, $pending);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'counted_cash' => 'The counted cash cannot be less than the cash already counted for pending incoming transfers.',
            ]);
        }

        return [$opening, $pending, $result];
    }

    /**
     * Read-only preview of what ending the shift with these figures would calculate. Same calculation as
     * `record`, nothing persisted. Amounts are SAR (halalas ÷ 100 at this boundary), as in `reconciliation`.
     *
     * @return array{cash_reconciliation:array<string,mixed>,shortage_to_allocate:float,allocation_required:bool}
     */
    public function preview(CashierShift $shift, int $grossHalalas, int $cardsHalalas, int $appsHalalas, int $countedHalalas, int $declaredPendingHalalas = 0): array
    {
        [$opening, $pending, $result] = $this->compute($shift, $grossHalalas, $cardsHalalas, $appsHalalas, $countedHalalas, $declaredPendingHalalas);
        $variance = (int) $result['variance'];

        return [
            'cash_reconciliation' => [
                'counted_cash' => $countedHalalas / 100,
                'expected_cash' => $result['expected'] / 100,
                'cash_variance' => $variance / 100,
                'cash_variance_type' => match (true) {
                    $variance < 0 => 'shortage',
                    $variance > 0 => 'surplus',
                    default => 'balanced',
                },
                'pending_incoming_cash' => $pending / 100,
                'confirmed_opening_cash' => $opening / 100,
            ],
            'shortage_to_allocate' => $variance < 0 ? -$variance / 100 : 0.0,
            'allocation_required' => $variance < 0,
        ];
    }

    /**
     * Calculate and persist the count for the revision just recorded. Must run inside the report
     * transaction, after the revision and the sales channels are written.
     *
     * @param  int  $grossHalalas  VAT-inclusive gross sales as persisted
     * @param  int  $declaredPendingHalalas  CASH-08: part of the drawer the cashier declares as undecided inbound cash
     */
    public function record(
        CashierShift $shift,
        ShiftReportRevision $revision,
        int $grossHalalas,
        int $cardsHalalas,
        int $appsHalalas,
        int $countedHalalas,
        int $declaredPendingHalalas = 0,
    ): ShiftReportCashCount {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('A cash count must be recorded inside the report transaction.');
        }

        [$opening, $pending, $result] = $this->compute($shift, $grossHalalas, $cardsHalalas, $appsHalalas, $countedHalalas, $declaredPendingHalalas);

        $count = ShiftReportCashCount::create([
            'report_revision_id' => $revision->id,
            'counted_revision_id' => $revision->id,
            'evidence_revision_id' => $revision->id,
            'cashier_shift_id' => $shift->id,
            'gross_halalas' => $grossHalalas,
            'cards_halalas' => $cardsHalalas,
            'apps_halalas' => $appsHalalas,
            'confirmed_opening_halalas' => $opening,
            'pending_incoming_counted_halalas' => $pending,
            'counted_halalas' => $countedHalalas,
            'expected_halalas' => $result['expected'],
            'variance_halalas' => $result['variance'],
        ]);
        DB::table('shift_report_aggregates')->where('id', $revision->report_aggregate_id)->update(['fresh_count_required' => false]);

        return $count;
    }

    /**
     * A handover request/correction advances the report revision without changing sales, channels or
     * the physical count. Carry the same immutable figures to the new revision, keeping the revision in
     * which they were established, so liability evidence for the unchanged report stays current.
     */
    public function carryForward(ShiftReportRevision $from, ShiftReportRevision $to): ?ShiftReportCashCount
    {
        if (DB::table('shift_report_aggregates')->where('id', $from->report_aggregate_id)->lockForUpdate()->value('fresh_count_required')) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('PHYSICAL_RECOUNT_REQUIRED');
        }
        $count = ShiftReportCashCount::query()->where('report_revision_id', $from->id)->lockForUpdate()->first();
        if ($count === null) {
            return null;
        }

        return ShiftReportCashCount::create([
            'report_revision_id' => $to->id,
            'counted_revision_id' => $count->counted_revision_id,
            'evidence_revision_id' => $count->evidence_revision_id ?? $count->counted_revision_id,
            'cashier_shift_id' => $count->cashier_shift_id,
            'gross_halalas' => $count->gross_halalas,
            'cards_halalas' => $count->cards_halalas,
            'apps_halalas' => $count->apps_halalas,
            'confirmed_opening_halalas' => $count->confirmed_opening_halalas,
            'pending_incoming_counted_halalas' => $count->pending_incoming_counted_halalas,
            'counted_halalas' => $count->counted_halalas,
            'expected_halalas' => $count->expected_halalas,
            'variance_halalas' => $count->variance_halalas,
        ]);
    }

    /**
     * Recalculates from corrected financial inputs while holding original physical observation constant.
     * counted_revision_id stays as sourceCount, while evidence_revision_id is set to the new revision.
     */
    public function recordCorrectedEvidence(
        CashierShift $shift,
        ShiftReportRevision $revision,
        ShiftReportCashCount $sourceCount,
        array $financialInputs,
    ): ShiftReportCashCount {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Corrected evidence must be recorded inside the report transaction.');
        }

        if (DB::table('shift_report_aggregates')->where('id', $revision->report_aggregate_id)->lockForUpdate()->value('fresh_count_required')) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('PHYSICAL_RECOUNT_REQUIRED');
        }

        $grossHalalas = $financialInputs['gross_halalas'];
        $cardsHalalas = $financialInputs['cards_halalas'];
        $appsHalalas = $financialInputs['apps_halalas'];

        $channels = ShiftFinancialCalculator::salesChannelCheck($grossHalalas, $cardsHalalas, $appsHalalas);
        if (! $channels['channelsValid']) {
            throw ValidationException::withMessages([
                'card_payments' => 'Card payments plus delivery-app sales cannot exceed gross sales.',
            ]);
        }

        $opening = $this->confirmedOpeningHalalas($shift->id);
        // The physical observation is constant: the stored pending incoming (D-11 evidence plus the cashier's
        // CASH-08 declaration) is carried unchanged. It stays valid only while the evidence still supports it:
        // the rejection evidence cannot exceed it and the remainder must still fit the undecided requests.
        $pending = $sourceCount->pending_incoming_counted_halalas;
        $declared = $pending - $this->pendingIncomingHalalas($shift);

        if ($opening !== $sourceCount->confirmed_opening_halalas || $declared < 0
            || ($declared > 0 && $declared > $this->undecidedInboundCapHalalas($shift))) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('PHYSICAL_RECOUNT_REQUIRED');
        }

        try {
            $result = ShiftFinancialCalculator::calculate(
                $grossHalalas,
                $cardsHalalas,
                $appsHalalas,
                $opening,
                $financialInputs['counted_halalas'] ?? $sourceCount->counted_halalas,
                $pending
            );
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'counted_cash' => 'The counted cash cannot be less than the cash already counted for pending incoming transfers.',
            ]);
        }

        return ShiftReportCashCount::create([
            'report_revision_id' => $revision->id,
            'counted_revision_id' => $sourceCount->counted_revision_id,
            'evidence_revision_id' => $revision->id,
            'cashier_shift_id' => $shift->id,
            'gross_halalas' => $grossHalalas,
            'cards_halalas' => $cardsHalalas,
            'apps_halalas' => $appsHalalas,
            'confirmed_opening_halalas' => $opening,
            'pending_incoming_counted_halalas' => $pending,
            'counted_halalas' => $financialInputs['counted_halalas'] ?? $sourceCount->counted_halalas,
            'expected_halalas' => $result['expected'],
            'variance_halalas' => $result['variance'],
        ]);
    }

    /** The count of the shift's CURRENT report revision, or null when that revision has none. */
    public function currentFor(string $cashierShiftId): ?ShiftReportCashCount
    {
        $aggregate = DB::table('shift_report_aggregates')
            ->where('source_type', 'cashier_shift')
            ->where('source_id', $cashierShiftId)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->first();
        if (! $aggregate) {
            return null;
        }

        $revisionId = DB::table('shift_report_revisions')
            ->where('report_aggregate_id', $aggregate->id)
            ->where('revision_number', $aggregate->current_revision_number)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->value('id');

        return $revisionId ? ShiftReportCashCount::query()->where('report_revision_id', $revisionId)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first() : null;
    }

    /**
     * D11: record the physical amount counted at a rejection for correction. Append-only; the
     * request itself keeps its original amount. Call inside the rejection transaction.
     */
    public function recordRejectionEvidence(
        ?string $handoverId,
        ?string $managerTransferId,
        string $recipientType,
        string $recipientId,
        ?string $receivingCashierShiftId,
        string $requestedSar,
        string $physicalSar,
        string $correctionReason,
        ?string $expectedAttemptId = null,
    ): ShiftTransferRejectionEvidence {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Rejection evidence must be recorded inside the rejection transaction.');
        }
        if (($handoverId === null) === ($managerTransferId === null)) {
            throw new \LogicException('Rejection evidence requires exactly one transfer request.');
        }

        $request = $handoverId
            ? \Modules\Shift\Models\CashierShiftHandover::whereKey($handoverId)->lockForUpdate()->firstOrFail()
            : \Modules\Shift\Models\BranchManagerCashTransfer::whereKey($managerTransferId)->lockForUpdate()->firstOrFail();
        $attemptId = $request->current_transfer_attempt_id;
        if ($attemptId) {
            $attempt = \Modules\Shift\Models\ShiftTransferAttempt::whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if ((string) $attempt->recipient_id !== (string) $recipientId || $attempt->recipient_type !== $recipientType
                || (string) $expectedAttemptId !== (string) $attemptId
                || (string) $attempt->report_revision_id !== (string) $request->report_revision_id
                || ShiftTransferRejectionEvidence::where('transfer_attempt_id', $attemptId)->exists()) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('STALE_TRANSFER_ATTEMPT');
            }
            $receivingCashierShiftId = $attempt->receiving_cashier_shift_id ?? $receivingCashierShiftId;
        }

        return ShiftTransferRejectionEvidence::create([
            'transfer_attempt_id' => $attemptId,
            'cashier_shift_handover_id' => $handoverId,
            'branch_manager_cash_transfer_id' => $managerTransferId,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'receiving_cashier_shift_id' => $receivingCashierShiftId,
            'requested_halalas' => ShiftFinancialCalculator::sarToHalalas($requestedSar),
            'physical_halalas' => ShiftFinancialCalculator::sarToHalalas($physicalSar),
            'correction_reason' => $correctionReason,
            'rejected_at' => now(),
        ]);
    }

    /**
     * Client-facing reconciliation of the CURRENT revision's count, in SAR (the unit of the legacy shift
     * resources). `null` means there is no count evidence; it is never a zero count. Every amount is an
     * exact integer-halalas value divided by 100 at this single boundary.
     *
     * @return array{counted_cash:float,expected_cash:float,cash_variance:float,cash_variance_type:string,pending_incoming_cash:float,confirmed_opening_cash:float}|null
     */
    public function reconciliation(string $cashierShiftId): ?array
    {
        $count = $this->currentFor($cashierShiftId);
        if ($count === null) {
            return null;
        }

        return [
            'counted_cash' => $count->counted_halalas / 100,
            'expected_cash' => $count->expected_halalas / 100,
            'cash_variance' => $count->variance_halalas / 100,
            'cash_variance_type' => match (true) {
                $count->variance_halalas < 0 => 'shortage',
                $count->variance_halalas > 0 => 'surplus',
                default => 'balanced',
            },
            'pending_incoming_cash' => $count->pending_incoming_counted_halalas / 100,
            'confirmed_opening_cash' => $count->confirmed_opening_halalas / 100,
        ];
    }

    /** Mark a single-shift response as carrying the reconciliation (lists never do: no N+1). */
    public function attachReconciliation(CashierShift $shift): CashierShift
    {
        return $shift->setRelation('cashReconciliation', $this->reconciliation($shift->id));
    }

    /** The recipient cashier's single open shift in the same branch and date, else null (ambiguous or none). */
    public function resolveReceivingShiftId(CashierShift $source, string $recipientCashierId): ?string
    {
        $matches = CashierShift::query()
            ->withoutEagerLoads()
            ->where('cashier_id', $recipientCashierId)
            ->whereDate('shift_date', $source->shift_date)
            ->whereIn('status', ['not_started', 'in_progress'])
            ->whereHas('shift', fn ($query) => $query->where('branch_id', $source->shift?->branch_id))
            ->limit(2)
            ->pluck('id');

        return $matches->count() === 1 ? (string) $matches->first() : null;
    }
}
