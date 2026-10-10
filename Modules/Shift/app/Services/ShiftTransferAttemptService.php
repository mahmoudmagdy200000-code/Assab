<?php

namespace Modules\Shift\Services;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftTransferAttempt;
use Modules\Shift\Models\ShiftTransferRejectionEvidence;
use Modules\Shift\Models\ShiftTransferReturn;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Explicit prospective physical commands. Historical untyped requests remain untyped. */
class ShiftTransferAttemptService
{
    public function __construct(private ShiftReportRevisionService $revisions, private ShiftCashCountService $counts) {}

    public function present(string $type, string $id, Model $actor, int $amount, string $key): ShiftTransferAttempt
    {
        return DB::transaction(function () use ($type, $id, $actor, $amount, $key) {
            [$source, $request] = $this->lockRequest($type, $id);
            $senderType = $source instanceof CashierShift ? 'cashier' : 'branch_manager';
            $senderId = $source instanceof CashierShift ? $source->cashier_id : $source->branch_manager_id;
            $this->assertActor($actor, $senderType, $senderId);
            $hash = hash('sha256', json_encode([$amount], JSON_THROW_ON_ERROR));
            $prior = ShiftTransferAttempt::where('request_type', $type)->where('request_id', $id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($prior) {
                if ($prior->payload_hash !== $hash) {
                    throw new ConflictHttpException('IDEMPOTENCY_PAYLOAD_MISMATCH');
                }

                return $prior;
            }
            if ($request->status !== 'pending' || $request->receipt()->lockForUpdate()->first() !== null) {
                throw new ConflictHttpException('TRANSFER_NOT_PENDING');
            }
            $this->assertCurrentRevision($source, $request);
            if ($source instanceof CashierShift) {
                $this->revisions->assertFreshCount($source);
            }
            $old = $request->current_transfer_attempt_id ? ShiftTransferAttempt::whereKey($request->current_transfer_attempt_id)->lockForUpdate()->firstOrFail() : null;
            if ($old && (ShiftTransferRejectionEvidence::where('transfer_attempt_id', $old->id)->lockForUpdate()->first() === null || $this->retained($old) > 0 || ShiftTransferReturn::where('transfer_attempt_id', $old->id)->whereNull('sender_confirmed_at')->lockForUpdate()->first() !== null)) {
                throw new ConflictHttpException('PREVIOUS_ATTEMPT_NOT_RETURNED');
            }
            // No historical rejection may be relabelled using today's branch/person data.
            if (! $old && ShiftTransferRejectionEvidence::query()->where($type === 'handover' ? 'cashier_shift_handover_id' : 'branch_manager_cash_transfer_id', $id)->lockForUpdate()->first() !== null) {
                throw new ConflictHttpException('LEGACY_ATTEMPT_IDENTITY_UNAVAILABLE');
            }
            $requested = ShiftFinancialCalculator::storedSarToHalalas($type === 'handover' ? $request->handover_amount : $request->requested_amount);
            if ($amount !== $requested) {
                throw new ConflictHttpException('PRESENTED_AMOUNT_MUST_MATCH_REQUEST');
            }
            $branch = $source instanceof CashierShift ? $source->shift()->value('branch_id') : $source->branch_id;
            $recipientType = $type === 'handover' ? $request->handover_to_type : 'cashier';
            $recipientId = $type === 'handover' ? $request->handover_to_id : $request->destination_cashier_id;
            $receiving = $type === 'handover'
                ? ($recipientType === 'cashier' ? $this->counts->resolveReceivingShiftId($source, $recipientId) : null)
                : $request->destination_cashier_shift_id;
            $attempt = ShiftTransferAttempt::create([
                'request_type' => $type, 'request_id' => $id, 'sequence' => ($old?->sequence ?? 0) + 1,
                'report_revision_id' => $request->report_revision_id, 'sender_type' => $senderType, 'sender_id' => $senderId,
                'recipient_type' => $recipientType, 'recipient_id' => $recipientId, 'source_branch_id' => $branch,
                'source_company_id' => DB::table('branches')->where('id', $branch)->value('asab_company_id'),
                'receiving_cashier_shift_id' => $receiving, 'presented_halalas' => $amount, 'presented_at' => now(),
                'idempotency_key' => $key, 'payload_hash' => $hash,
            ]);
            $request->forceFill(['current_transfer_attempt_id' => $attempt->id])->save();

            return $attempt;
        });
    }

    public function initiate(string $attemptId, Model $actor, int $amount, string $key, string $reason, ?string $evidence): ShiftTransferReturn
    {
        return DB::transaction(function () use ($attemptId, $actor, $amount, $key, $reason, $evidence) {
            $stub = ShiftTransferAttempt::findOrFail($attemptId);
            [, $request] = $this->lockRequest($stub->request_type, $stub->request_id, $stub->receiving_cashier_shift_id);
            $attempt = ShiftTransferAttempt::whereKey($attemptId)->lockForUpdate()->firstOrFail();
            $this->assertActor($actor, $attempt->recipient_type, $attempt->recipient_id);
            $hash = hash('sha256', json_encode([$amount, $reason, $evidence], JSON_THROW_ON_ERROR));
            $prior = ShiftTransferReturn::where('transfer_attempt_id', $attemptId)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($prior) {
                if ($prior->payload_hash !== $hash) {
                    throw new ConflictHttpException('IDEMPOTENCY_PAYLOAD_MISMATCH');
                }

                return $prior;
            }
            $this->assertReturnable($request, $attempt);
            $rejection = ShiftTransferRejectionEvidence::where('transfer_attempt_id', $attemptId)->lockForUpdate()->firstOrFail();
            // InnoDB locking reads see commits that happened while waiting for the attempt lock;
            // a snapshot SUM would otherwise allow two concurrent reservations to exceed physical cash.
            $reserved = (int) ShiftTransferReturn::where('transfer_attempt_id', $attemptId)->orderBy('id')->lockForUpdate()->get(['returned_halalas'])->sum('returned_halalas');
            if ($amount <= 0 || $reserved + $amount > $rejection->physical_halalas) {
                throw new ConflictHttpException('RETURN_EXCEEDS_RETAINED_CASH');
            }

            return ShiftTransferReturn::create([
                'transfer_attempt_id' => $attemptId, 'rejection_evidence_id' => $rejection->id, 'returned_halalas' => $amount,
                'initiated_by_type' => $attempt->recipient_type, 'initiated_by_id' => $actor->getKey(), 'initiated_at' => now(),
                'reason' => $reason, 'evidence_reference' => $evidence, 'idempotency_key' => $key, 'payload_hash' => $hash,
            ]);
        });
    }

    public function confirmReturn(string $id, Model $actor): ShiftTransferReturn
    {
        return DB::transaction(function () use ($id, $actor) {
            $stub = ShiftTransferReturn::findOrFail($id);
            $attemptStub = ShiftTransferAttempt::findOrFail($stub->transfer_attempt_id);
            $rejectionStub = ShiftTransferRejectionEvidence::findOrFail($stub->rejection_evidence_id);
            $receivingId = $this->receivingEvidenceShiftId($attemptStub, $rejectionStub);
            // Match manager receipt/daily-close ordering: receiving manager day, legacy cashier rows,
            // request, attempt, return, receiving report aggregate/count, Admin projection.
            $day = $receivingId ? CashierShift::withoutEagerLoads()->findOrFail($receivingId)->shift_date : $rejectionStub->rejected_at;
            $managerDays = BranchManagerShift::where('branch_id', $attemptStub->source_branch_id)->whereDate('shift_date', $day)
                ->when($attemptStub->recipient_type === 'branch_manager', fn ($q) => $q->where('branch_manager_id', $attemptStub->recipient_id))
                ->orderBy('id')->lockForUpdate()->get();
            [, $request] = $this->lockRequest($attemptStub->request_type, $attemptStub->request_id, $receivingId);
            $attempt = ShiftTransferAttempt::whereKey($attemptStub->id)->lockForUpdate()->firstOrFail();
            $return = ShiftTransferReturn::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertActor($actor, $attempt->sender_type, $attempt->sender_id);
            if ($return->sender_confirmed_at !== null) {
                return $return;
            }
            $this->assertReturnable($request, $attempt);
            if ($return->returned_halalas > $this->retained($attempt)) {
                throw new ConflictHttpException('RETURN_EXCEEDS_RETAINED_CASH');
            }
            $rejection = ShiftTransferRejectionEvidence::whereKey($return->rejection_evidence_id)->lockForUpdate()->firstOrFail();
            if ($receivingId !== $this->receivingEvidenceShiftId($attempt, $rejection)) {
                throw new ConflictHttpException('RECEIVING_SHIFT_CHANGED');
            }
            if ($receivingId) {
                $receiving = CashierShift::withoutEagerLoads()->findOrFail($receivingId);
                $aggregate = DB::table('shift_report_aggregates')->where('source_type', 'cashier_shift')->where('source_id', $receivingId)->lockForUpdate()->first();
                $currentCount = $aggregate ? DB::table('shift_report_cash_counts as counts')
                    ->join('shift_report_revisions as revisions', 'revisions.id', '=', 'counts.report_revision_id')
                    ->where('revisions.report_aggregate_id', $aggregate->id)->where('revisions.revision_number', $aggregate->current_revision_number)
                    ->select('counts.*')->lockForUpdate()->first() : null;
                $this->assertEditable($receiving, $managerDays);
                if ($currentCount !== null) {
                    $this->revisions->recordCashierRevision($receiving, $attempt->sender_type, (string) $actor->getKey());
                    DB::table('shift_report_aggregates')->where('source_type', 'cashier_shift')->where('source_id', $receivingId)->update(['fresh_count_required' => true]);
                    $receiving->recordHistory('physical_return_requires_recount', null, ['transfer_attempt_id' => $attempt->id, 'return_id' => $return->id]);
                }
            } elseif ($attempt->recipient_type === 'branch_manager') {
                // Manager reports have no independent cashier count primitive. Never reopen a submitted day.
                if ($managerDays->contains(fn ($row) => $row->daily_report_submitted)) {
                    throw new ConflictHttpException('REPORT_REOPEN_REQUIRED');
                }
            }
            $return->update(['sender_confirmed_by_type' => $attempt->sender_type, 'sender_confirmed_by_id' => $actor->getKey(), 'sender_confirmed_at' => now()]);

            return $return;
        });
    }

    /** Called only after the established receipt writer acquired its source/destination/request locks. */
    public function assertReceivable(Model $request, ?string $expectedAttemptId = null): ?ShiftTransferAttempt
    {
        if (! $request->current_transfer_attempt_id) {
            return null;
        }
        $attempt = ShiftTransferAttempt::whereKey($request->current_transfer_attempt_id)->lockForUpdate()->firstOrFail();
        if ((string) $expectedAttemptId !== (string) $attempt->id
            || (string) $request->report_revision_id !== (string) $attempt->report_revision_id
            || ShiftTransferRejectionEvidence::where('transfer_attempt_id', $attempt->id)->lockForUpdate()->first() !== null
            || ShiftTransferReturn::where('transfer_attempt_id', $attempt->id)->lockForUpdate()->first() !== null) {
            throw new ConflictHttpException('STALE_TRANSFER_ATTEMPT');
        }
        $type = $request instanceof CashierShiftHandover ? $request->handover_to_type : 'cashier';
        $id = $request instanceof CashierShiftHandover ? $request->handover_to_id : $request->destination_cashier_id;
        $amount = $request instanceof CashierShiftHandover ? $request->handover_amount : $request->requested_amount;
        if ($type !== $attempt->recipient_type || (string) $id !== (string) $attempt->recipient_id || ShiftFinancialCalculator::storedSarToHalalas($amount) !== $attempt->presented_halalas) {
            throw new ConflictHttpException('STALE_TRANSFER_ATTEMPT');
        }

        return $attempt;
    }

    public function retained(ShiftTransferAttempt $attempt): int
    {
        $physical = (int) ShiftTransferRejectionEvidence::where('transfer_attempt_id', $attempt->id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->value('physical_halalas');
        $returned = (int) ShiftTransferReturn::where('transfer_attempt_id', $attempt->id)->whereNotNull('sender_confirmed_at')->orderBy('id')
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->get(['returned_halalas'])->sum('returned_halalas');

        return max(0, $physical - $returned);
    }

    public function rejectAttempt(string $id, Model $actor, int $physical, string $reason, string $correctionReason): void
    {
        DB::transaction(function () use ($id, $actor, $physical, $reason, $correctionReason) {
            $stub = ShiftTransferAttempt::findOrFail($id);
            [$source, $request] = $this->lockRequest($stub->request_type, $stub->request_id, $stub->receiving_cashier_shift_id);
            $attempt = $this->assertReceivable($request, $id);
            if (! $attempt) {
                throw new ConflictHttpException('STALE_TRANSFER_ATTEMPT');
            }
            $this->assertActor($actor, $attempt->recipient_type, $attempt->recipient_id);
            $sar = intdiv($physical, 100).'.'.str_pad((string) ($physical % 100), 2, '0', STR_PAD_LEFT);
            if ($attempt->request_type === 'manager_transfer') {
                app(ShiftTransferReceiptService::class)->rejectManagerCashTransfer($request->id, $actor, $sar, $reason, $correctionReason, $id);
            } else {
                app(HandoverService::class)->rejectHandoverForAmountCorrection($source, $actor->getKey(), $attempt->recipient_type, $reason, $sar, $correctionReason, $id);
            }
        });
    }

    public function confirmReceipt(string $id, Model $actor, int $physical): Model
    {
        $attempt = ShiftTransferAttempt::findOrFail($id);
        $this->assertActor($actor, $attempt->recipient_type, $attempt->recipient_id);
        $sar = intdiv($physical, 100).'.'.str_pad((string) ($physical % 100), 2, '0', STR_PAD_LEFT);
        $receipts = app(ShiftTransferReceiptService::class);
        if ($attempt->request_type === 'manager_transfer') {
            return $receipts->confirmManagerCashTransfer($attempt->request_id, $actor, $sar, $id);
        }
        if ($attempt->recipient_type === 'branch_manager') {
            return $receipts->confirmManagerHandover($attempt->request_id, $actor, $sar, null, $id);
        }

        return $receipts->confirmHandover($attempt->request_id, $actor, $sar, $attempt->receiving_cashier_shift_id, null, null, $id);
    }

    private function assertReturnable(Model $request, ShiftTransferAttempt $attempt): void
    {
        if ((string) $request->current_transfer_attempt_id !== (string) $attempt->id || $request->receipt()->lockForUpdate()->first() !== null
            || ShiftTransferRejectionEvidence::where('transfer_attempt_id', $attempt->id)->lockForUpdate()->first() === null) {
            throw new ConflictHttpException('STALE_TRANSFER_ATTEMPT');
        }
    }

    private function assertCurrentRevision(Model $source, Model $request): void
    {
        $current = $source instanceof CashierShift ? $this->revisions->currentCashierRevision($source) : $this->revisions->currentManagerRevision($source);
        if (! $current || (string) $current->id !== (string) $request->report_revision_id) {
            throw new ConflictHttpException('STALE_REPORT_REVISION');
        }
    }

    private function assertActor(Model $actor, string $type, string $id): void
    {
        if (! (($type === 'cashier' && $actor instanceof Cashier) || ($type === 'branch_manager' && $actor instanceof BranchManager)) || (string) $actor->getKey() !== (string) $id) {
            throw new AccessDeniedHttpException('ONLY_ORIGINAL_TRANSFER_PARTICIPANT');
        }
    }

    /** Same order as receipts: manager source first; cashier rows ordered by id; request; attempt. */
    private function lockRequest(string $type, string $id, ?string $receivingId = null): array
    {
        if ($type === 'handover') {
            $stub = CashierShiftHandover::findOrFail($id);
            $ids = array_unique(array_filter([$stub->cashier_shift_id, $receivingId]));
            sort($ids, SORT_STRING);
            $rows = [];
            foreach ($ids as $shiftId) {
                $rows[$shiftId] = CashierShift::withoutEagerLoads()->whereKey($shiftId)->lockForUpdate()->firstOrFail();
            }

            return [$rows[$stub->cashier_shift_id], CashierShiftHandover::whereKey($id)->lockForUpdate()->firstOrFail()];
        }
        if ($type !== 'manager_transfer') {
            abort(404);
        }
        $stub = BranchManagerCashTransfer::findOrFail($id);
        $source = BranchManagerShift::whereKey($stub->branch_manager_shift_id)->lockForUpdate()->firstOrFail();
        CashierShift::withoutEagerLoads()->whereKey($stub->destination_cashier_shift_id)->lockForUpdate()->firstOrFail();

        return [$source, BranchManagerCashTransfer::whereKey($id)->lockForUpdate()->firstOrFail()];
    }

    private function assertEditable(CashierShift $shift, ?\Illuminate\Support\Collection $managerDays = null): void
    {
        $branch = $shift->shift()->value('branch_id');
        $managerDays ??= BranchManagerShift::where('branch_id', $branch)->whereDate('shift_date', $shift->shift_date)->orderBy('id')->lockForUpdate()->get();
        if (CashierShiftHandover::where('cashier_shift_id', $shift->id)->whereNotNull('daily_closed_at')->lockForUpdate()->first() !== null
            || $managerDays->contains(fn ($row) => $row->daily_report_submitted)
            || DB::table('asab_shifts')->where('legacy_shift_id', $shift->id)->whereIn('status', ['closed', 'pending_review'])->lockForUpdate()->first() !== null
            || DB::table('asab_operations')->where('module_key', 'shifts')->whereIn('source_id', DB::table('asab_shifts')->where('legacy_shift_id', $shift->id)->select('id'))->where('status', 'final-approved')->exists()) {
            throw new ConflictHttpException('REPORT_REOPEN_REQUIRED');
        }
    }

    /** Same D19 deterministic first-start rule as the count reader, using immutable attempt branch. */
    private function receivingEvidenceShiftId(ShiftTransferAttempt $attempt, ShiftTransferRejectionEvidence $evidence): ?string
    {
        if ($evidence->receiving_cashier_shift_id) {
            return $evidence->receiving_cashier_shift_id;
        }
        if ($attempt->recipient_type !== 'cashier') {
            return null;
        }

        return DB::table('cashier_shifts')->join('shifts', 'shifts.id', '=', 'cashier_shifts.shift_id')
            ->where('cashier_shifts.cashier_id', $attempt->recipient_id)->where('shifts.branch_id', $attempt->source_branch_id)
            ->where('cashier_shifts.actual_start_time', '>=', $evidence->rejected_at)
            ->orderBy('cashier_shifts.actual_start_time')->orderBy('cashier_shifts.id')->value('cashier_shifts.id');
    }

    public function recount(CashierShift $shift, Model $actor, int $amount): Model
    {
        return DB::transaction(function () use ($shift, $actor, $amount) {
            $snapshot = CashierShift::withoutEagerLoads()->findOrFail($shift->id);
            $managerDays = BranchManagerShift::where('branch_id', $snapshot->shift()->value('branch_id'))->whereDate('shift_date', $snapshot->shift_date)->orderBy('id')->lockForUpdate()->get();
            $shift = CashierShift::withoutEagerLoads()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $this->assertActor($actor, 'cashier', $shift->cashier_id);
            $this->assertEditable($shift, $managerDays);
            if (! DB::table('shift_report_aggregates')->where('source_type', 'cashier_shift')->where('source_id', $shift->id)->lockForUpdate()->value('fresh_count_required')) {
                throw new ConflictHttpException('PHYSICAL_RECOUNT_NOT_REQUIRED');
            }
            $old = DB::table('shift_report_cash_counts as counts')
                ->join('shift_report_revisions as revisions', 'revisions.id', '=', 'counts.report_revision_id')
                ->where('counts.cashier_shift_id', $shift->id)->orderByDesc('revisions.revision_number')->select('counts.*')->lockForUpdate()->first();
            if (! $old) {
                throw new ConflictHttpException('PHYSICAL_COUNT_EVIDENCE_REQUIRED');
            }
            $revision = $this->revisions->recordCashierRevision($shift, 'cashier', $actor->getKey());
            $count = $this->counts->record($shift, $revision, $old->gross_halalas, $old->cards_halalas, $old->apps_halalas, $amount);
            $shift->recordHistory('physical_return_recounted', null, ['report_revision_id' => $revision->id, 'count_id' => $count->id]);

            return $count;
        });
    }
}
