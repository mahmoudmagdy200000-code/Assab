<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Services\CommandIdempotencyContext;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Enums\TransactionType;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\CashierShiftHistory;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftReportCashCount;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Sole S1-08 writer for confirmed transfer receipts and their required effects. */
class ShiftTransferReceiptService
{
    public function __construct(private ShiftReportRevisionService $revisions, private ShiftCashCountService $cashCounts) {}

    public function requestManagerCashTransfer(
        BranchManagerShift $source,
        Cashier $destinationCashier,
        CashierShift $destinationShift,
        string $amount,
        BranchManager $actor
    ): BranchManagerCashTransfer {
        $requestedMinor = self::toMinorUnits($amount);

        if ($requestedMinor < 0) {
            throw ValidationException::withMessages(['requested_amount' => 'Amount must be nonnegative.']);
        }

        return DB::transaction(function () use ($source, $destinationCashier, $destinationShift, $actor, $amount, $requestedMinor) {
            $source = BranchManagerShift::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $destinationShift = CashierShift::query()->whereKey($destinationShift->id)->lockForUpdate()->firstOrFail();

            if ((string) $source->branch_manager_id !== (string) $actor->id
                || (string) $source->branch_id !== (string) $actor->branch_id) {
                throw new AccessDeniedHttpException('ONLY_SOURCE_BRANCH_MANAGER');
            }

            $destination = DB::table('cashier_shifts')
                ->join('cashiers', 'cashiers.id', '=', 'cashier_shifts.cashier_id')
                ->join('shifts', 'shifts.id', '=', 'cashier_shifts.shift_id')
                ->join('branches', 'branches.id', '=', 'shifts.branch_id')
                ->where('cashiers.id', $destinationCashier->id)
                ->where('cashier_shifts.id', $destinationShift->id)
                ->where('cashier_shifts.cashier_id', $destinationCashier->id)
                ->where('branches.id', $source->branch_id)
                ->select('cashier_shifts.id')
                ->first();

            if (! $destination || ! in_array((string) $destinationShift->getRawOriginal('status'), ['not_started', 'in_progress'], true)) {
                throw new ConflictHttpException('RECEIVING_SHIFT_NOT_AVAILABLE');
            }

            // D14: a transfer rejected for an amount correction is corrected, not replaced.
            $pendingCorrection = BranchManagerCashTransfer::query()
                ->where('branch_manager_shift_id', $source->id)
                ->where('destination_cashier_id', $destinationCashier->id)
                ->where('status', 'rejected')
                ->whereDoesntHave('receipt')
                ->whereIn('id', \Modules\Shift\Models\ShiftTransferRejectionEvidence::query()->whereNotNull('branch_manager_cash_transfer_id')->select('branch_manager_cash_transfer_id'))
                ->exists();
            if ($pendingCorrection) {
                throw new ConflictHttpException('HANDOVER_CORRECTION_PENDING');
            }

            $currentRevision = $this->revisions->currentManagerRevision($source);
            if (! $currentRevision) {
                $currentRevision = $this->revisions->recordManagerRevision($source, 'branch_manager', $actor->id, 0);
            }

            $availableMinor = $this->availableManagerCashMinor($source->branch_manager_id);

            if ($requestedMinor > $availableMinor) {
                throw new ConflictHttpException('INSUFFICIENT_RECORDED_SALES_CASH');
            }

            return BranchManagerCashTransfer::create([
                'branch_manager_shift_id' => $source->id,
                'destination_cashier_id' => $destinationCashier->id,
                'destination_cashier_shift_id' => $destinationShift->id,
                'report_revision_id' => $currentRevision->id,
                'created_by_id' => $actor->id,
                'requested_amount' => $amount,
                'status' => 'pending',
            ]);
        });
    }

    public function rejectManagerCashTransfer(string $transferId, Cashier $recipient, string $attemptedConfirmedAmount, string $reason, string $correctionReason, ?string $expectedAttemptId = null): BranchManagerCashTransfer
    {
        $this->assertCorrectionReason($correctionReason);
        $attemptedMinor = self::toMinorUnits($attemptedConfirmedAmount);

        return DB::transaction(function () use ($transferId, $recipient, $attemptedConfirmedAmount, $attemptedMinor, $reason, $correctionReason, $expectedAttemptId) {
            $stub = BranchManagerCashTransfer::query()->whereKey($transferId)->firstOrFail();
            $source = BranchManagerShift::query()->whereKey($stub->branch_manager_shift_id)->lockForUpdate()->firstOrFail();
            $destination = CashierShift::withoutEagerLoads()->whereKey($stub->destination_cashier_shift_id)->lockForUpdate()->firstOrFail();
            $transfer = BranchManagerCashTransfer::query()->whereKey($transferId)->lockForUpdate()->firstOrFail();

            if ((string) $transfer->destination_cashier_id !== (string) $recipient->id
                || (string) $destination->cashier_id !== (string) $recipient->id) {
                throw new AccessDeniedHttpException('ONLY_NAMED_CASHIER_RECIPIENT');
            }
            if ($transfer->status !== 'pending' || $transfer->receipt()->exists()) {
                throw new ConflictHttpException('TRANSFER_NOT_CORRECTABLE');
            }
            if ($attemptedMinor === self::toMinorUnits((string) $transfer->requested_amount)) {
                throw new ConflictHttpException('HANDOVER_AMOUNT_MISMATCH_REQUIRED_FOR_CORRECTION');
            }

            $transfer->update(['status' => 'rejected']);
            // D11: structured physical amount, pending incoming in the destination shift's count.
            $this->cashCounts->recordRejectionEvidence(
                null,
                $transfer->id,
                'cashier',
                (string) $recipient->id,
                (string) $destination->id,
                (string) $transfer->requested_amount,
                $attemptedConfirmedAmount,
                $correctionReason,
                $expectedAttemptId,
            );
            $this->writeTransferCorrectionHistory($destination, $recipient->id, 'cashier', 'manager_transfer_amount_correction_rejected', [
                'transfer_id' => $transfer->id,
                'requested_amount' => (string) $transfer->requested_amount,
                'attempted_confirmed_amount' => $attemptedConfirmedAmount,
                'correction_reason' => $correctionReason,
                'rejection_reason' => $reason,
                'report_revision_id' => $transfer->report_revision_id,
                'source_manager_shift_id' => $source->id,
            ]);

            return $transfer;
        });
    }

    public function correctManagerCashTransfer(string $transferId, BranchManager $actor, string $newAmount, string $correctionReason): BranchManagerCashTransfer
    {
        $this->assertCorrectionReason($correctionReason);
        $newMinor = self::toMinorUnits($newAmount);

        return DB::transaction(function () use ($transferId, $actor, $newAmount, $newMinor, $correctionReason) {
            $stub = BranchManagerCashTransfer::query()->whereKey($transferId)->firstOrFail();
            $source = BranchManagerShift::query()->whereKey($stub->branch_manager_shift_id)->lockForUpdate()->firstOrFail();
            $destination = CashierShift::withoutEagerLoads()->whereKey($stub->destination_cashier_shift_id)->lockForUpdate()->firstOrFail();
            $transfer = BranchManagerCashTransfer::query()->whereKey($transferId)->lockForUpdate()->firstOrFail();

            if ((string) $source->branch_manager_id !== (string) $actor->id
                || (string) $source->branch_id !== (string) $actor->branch_id) {
                throw new AccessDeniedHttpException('ONLY_SOURCE_BRANCH_MANAGER');
            }
            if ($transfer->status !== 'rejected' || $transfer->receipt()->exists()) {
                throw new ConflictHttpException('TRANSFER_NOT_CORRECTABLE');
            }

            $revision = $this->revisions->currentManagerRevision($source);
            if (! $revision) {
                throw new ConflictHttpException('TRANSFER_REPORT_REVISION_REQUIRED');
            }
            if ($newMinor > $this->availableManagerCashMinor($source->branch_manager_id)) {
                throw new ConflictHttpException('INSUFFICIENT_RECORDED_SALES_CASH');
            }

            $oldAmount = (string) $transfer->requested_amount;
            $transfer->update([
                'requested_amount' => $newAmount,
                'report_revision_id' => $revision->id,
                'status' => 'pending',
            ]);
            $this->writeTransferCorrectionHistory($destination, $actor->id, 'branch_manager', 'manager_transfer_request_corrected', [
                'transfer_id' => $transfer->id,
                'old_requested_amount' => $oldAmount,
                'new_requested_amount' => $newAmount,
                'correction_reason' => $correctionReason,
                'report_revision_id' => $revision->id,
                'source_manager_shift_id' => $source->id,
                'destination_cashier_shift_id' => $destination->id,
                'evidence_note' => $correctionReason === 'actual_shortage'
                    ? 'Reported physical shortage retained for later trusted evidence review; no liability allocation inferred.'
                    : 'Request amount corrected after identifying an input error.',
            ]);

            return $transfer;
        });
    }

    public function confirmHandover(
        string $handoverId,
        Cashier $recipient,
        string $confirmedAmount,
        ?string $receivingShiftId = null,
        ?string $comment = null,
        ?Response $commandResponse = null,
        ?string $expectedAttemptId = null
    ): CashierShiftHandoverReceipt {
        return DB::transaction(function () use ($handoverId, $recipient, $confirmedAmount, $receivingShiftId, $comment, $commandResponse, $expectedAttemptId) {
            if ($commandResponse !== null && app()->bound(CommandIdempotencyContext::class)) {
                app(CommandIdempotencyContext::class)->lockForAuthoritativeWrite();
            }

            $handoverStub = CashierShiftHandover::query()->whereKey($handoverId)->firstOrFail();
            $sourceSnapshot = CashierShift::query()->whereKey($handoverStub->cashier_shift_id)->firstOrFail();
            $destinationSnapshot = $this->resolveReceivingShift($sourceSnapshot, $recipient, $receivingShiftId);
            [$source, $destination] = $this->lockCashierShiftsInOrder($sourceSnapshot->id, $destinationSnapshot->id);
            $handover = CashierShiftHandover::query()->whereKey($handoverId)->lockForUpdate()->firstOrFail();

            if ($handover->handover_to_type !== 'cashier' || (string) $handover->handover_to_id !== (string) $recipient->id) {
                throw new AccessDeniedHttpException('ONLY_NAMED_CASHIER_RECIPIENT');
            }
            if ($handover->status !== 'pending') {
                throw new ConflictHttpException('HANDOVER_NOT_PENDING');
            }

            $revision = $this->revisions->currentCashierRevision($source);
            if (! $revision || (string) $revision->id !== (string) $handover->report_revision_id) {
                throw new ConflictHttpException('STALE_REPORT_REVISION');
            }

            $count = ShiftReportCashCount::query()->where('report_revision_id', $revision->id)->first();
            if (! $count || $source->status === ShiftStatus::IN_PROGRESS) {
                throw new ConflictHttpException('REPORT_COUNT_REQUIRED');
            }

            $this->assertReceivingShift($source, $destination, $recipient);

            $receipt = $this->recordReceipt(
                $handover,
                null,
                $source,
                $destination,
                $recipient,
                $revision->id,
                $confirmedAmount,
                $comment,
                null,
                $expectedAttemptId
            );

            if ($commandResponse !== null && app()->bound(CommandIdempotencyContext::class)) {
                app(CommandIdempotencyContext::class)->completeWithinBusinessTransaction($commandResponse);
            }

            return $receipt;
        });
    }

    /** A manager-addressed cashier handover is received only by that manager. */
    public function confirmManagerHandover(
        string $handoverId,
        BranchManager $recipient,
        string $confirmedAmount,
        ?string $comment = null,
        ?string $expectedAttemptId = null
    ): CashierShiftHandoverReceipt {
        return DB::transaction(function () use ($handoverId, $recipient, $confirmedAmount, $comment, $expectedAttemptId) {
            $stub = CashierShiftHandover::query()->whereKey($handoverId)->firstOrFail();
            $sourceSnapshot = CashierShift::withoutEagerLoads()->whereKey($stub->cashier_shift_id)->firstOrFail();
            $branchId = $sourceSnapshot->shift()->value('branch_id');
            app(\Modules\BranchManagers\Services\BranchManagerService::class)
                ->assertAssignedActiveManager($branchId, $recipient->id);
            $managerWorkday = BranchManagerShift::query()
                ->where('branch_manager_id', $recipient->id)
                ->where('branch_id', $branchId)
                ->whereDate('shift_date', today())
                ->first();

            if (! $managerWorkday) {
                throw new ConflictHttpException('RECEIVING_MANAGER_WORKDAY_REQUIRED');
            }

            // Match manager-close ordering: manager aggregate, cashier source, request.
            $managerWorkday = BranchManagerShift::query()->whereKey($managerWorkday->id)->lockForUpdate()->firstOrFail();
            $source = CashierShift::withoutEagerLoads()->whereKey($sourceSnapshot->id)->lockForUpdate()->firstOrFail();
            $handover = CashierShiftHandover::query()->whereKey($handoverId)->lockForUpdate()->firstOrFail();

            if ($handover->handover_to_type !== 'branch_manager'
                || (string) $handover->handover_to_id !== (string) $recipient->id
                || (string) $managerWorkday->branch_manager_id !== (string) $recipient->id
                || (string) $managerWorkday->branch_id !== (string) $branchId) {
                throw new AccessDeniedHttpException('ONLY_ADDRESSED_BRANCH_MANAGER_RECIPIENT');
            }
            if ($handover->status !== 'pending') {
                throw new ConflictHttpException('HANDOVER_NOT_PENDING');
            }

            $revision = $this->revisions->currentCashierRevision($source);
            if (! $revision || (string) $revision->id !== (string) $handover->report_revision_id) {
                throw new ConflictHttpException('STALE_REPORT_REVISION');
            }

            $count = ShiftReportCashCount::query()->where('report_revision_id', $revision->id)->first();
            if (! $count || $source->status === ShiftStatus::IN_PROGRESS) {
                throw new ConflictHttpException('REPORT_COUNT_REQUIRED');
            }

            return $this->recordReceipt(
                $handover,
                null,
                $source,
                null,
                null,
                $revision->id,
                $confirmedAmount,
                $comment,
                $managerWorkday,
                $expectedAttemptId
            );
        });
    }

    public function confirmManagerCashTransfer(
        string $transferId,
        Cashier $recipient,
        string $confirmedAmount,
        ?string $expectedAttemptId = null
    ): CashierShiftHandoverReceipt {
        return DB::transaction(function () use ($transferId, $recipient, $confirmedAmount, $expectedAttemptId) {
            $stub = BranchManagerCashTransfer::query()->whereKey($transferId)->firstOrFail();
            $source = BranchManagerShift::query()->whereKey($stub->branch_manager_shift_id)->lockForUpdate()->firstOrFail();
            $destination = CashierShift::query()->whereKey($stub->destination_cashier_shift_id)->lockForUpdate()->firstOrFail();
            $transfer = BranchManagerCashTransfer::query()->whereKey($transferId)->lockForUpdate()->firstOrFail();

            if ((string) $transfer->branch_manager_shift_id !== (string) $source->id
                || (string) $transfer->destination_cashier_shift_id !== (string) $destination->id) {
                throw new ConflictHttpException('TRANSFER_CHANGED_DURING_CONFIRMATION');
            }

            if ((string) $transfer->destination_cashier_id !== (string) $recipient->id
                || (string) $destination->cashier_id !== (string) $recipient->id) {
                throw new AccessDeniedHttpException('ONLY_NAMED_CASHIER_RECIPIENT');
            }
            if ($transfer->status !== 'pending') {
                throw new ConflictHttpException('TRANSFER_NOT_PENDING');
            }
            $currentRevision = $this->revisions->currentManagerRevision($source);
            if (! $transfer->report_revision_id || ! $currentRevision || (string) $currentRevision->id !== (string) $transfer->report_revision_id) {
                throw new ConflictHttpException('TRANSFER_REPORT_REVISION_REQUIRED');
            }

            $this->assertReceivingShiftBranch($source->branch_id, $destination, $recipient);

            return $this->recordReceipt(
                null,
                $transfer,
                $source,
                $destination,
                $recipient,
                $transfer->report_revision_id,
                $confirmedAmount,
                null,
                null,
                $expectedAttemptId
            );
        });
    }

    private function recordReceipt(
        ?CashierShiftHandover $handover,
        ?BranchManagerCashTransfer $managerTransfer,
        CashierShift|BranchManagerShift $source,
        ?CashierShift $destination,
        ?Cashier $recipient,
        string $revisionId,
        string $confirmedAmount,
        ?string $comment,
        ?BranchManagerShift $managerDestination,
        ?string $expectedAttemptId = null
    ): CashierShiftHandoverReceipt {
        if (($handover === null) === ($managerTransfer === null)) {
            throw new \LogicException('Receipt requires exactly one transfer source.');
        }

        if ($source instanceof CashierShift) {
            $this->revisions->assertFreshCount($source);
        }
        $attempt = app(ShiftTransferAttemptService::class)->assertReceivable($handover ?? $managerTransfer, $expectedAttemptId);

        $confirmedMinor = self::toMinorUnits($confirmedAmount);
        $requestedMinor = self::toMinorUnits((string) ($handover?->handover_amount ?? $managerTransfer?->requested_amount));
        if ($confirmedMinor < 0) {
            throw ValidationException::withMessages(['confirmed_amount' => 'Confirmed amount must be nonnegative.']);
        }
        if ($confirmedMinor !== $requestedMinor) {
            throw new ConflictHttpException('HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED');
        }

        $receipt = CashierShiftHandoverReceipt::create([
            'transfer_attempt_id' => $attempt?->id,
            'cashier_shift_handover_id' => $handover?->id,
            'branch_manager_cash_transfer_id' => $managerTransfer?->id,
            'receiving_cashier_shift_id' => $destination?->id,
            'receiving_cashier_id' => $recipient?->id,
            'receiving_branch_manager_shift_id' => $managerDestination?->id,
            'receiving_branch_manager_id' => $managerDestination?->branch_manager_id,
            'report_revision_id' => $revisionId,
            'confirmed_amount' => self::toSar($confirmedMinor),
            'confirmed_by_id' => $recipient?->id,
            'confirmed_by_branch_manager_id' => $managerDestination?->branch_manager_id,
            'confirmed_at' => now(),
        ]);

        if ($handover && $managerDestination) {
            $sender = $source->cashier;
            $manager = $managerDestination->branchManager;
            if (! $manager || ($confirmedMinor > 0 && ! $sender)) {
                throw new ConflictHttpException('RECEIVING_MANAGER_REQUIRED');
            }
            if ($confirmedMinor > 0) {
                $this->createCustodyMovement($receipt, $sender, 'Handover Sent', false, $confirmedMinor, $manager->name, $source->id);
                PersonalLedgerTransaction::create([
                    'branch_manager_id' => $manager->id,
                    'transaction_type' => 'Total Sales',
                    'amount' => self::toSar($confirmedMinor),
                    'is_cash_in' => true,
                    'cashier_name' => $sender->name,
                    'related_shift_id' => $managerDestination->id,
                    'related_handover_id' => $handover->id,
                    'receipt_id' => $receipt->id,
                    'transaction_date' => now(),
                ]);
            }
            $handover->update([
                'status' => 'approved',
                'approved_by_id' => $manager->id,
                'approved_by_type' => BranchManager::class,
                'approved_at' => now(),
            ]);
            ShiftHandoverStatus::query()->where('cashier_shift_id', $handover->cashier_shift_id)->firstOrFail()
                ->approve($manager->id, BranchManager::class, $comment);
        } elseif ($confirmedMinor > 0 && $handover) {
            $sender = $source->cashier;
            if (! $sender || ! $recipient || ! $destination) {
                throw new ConflictHttpException('SENDING_CASHIER_REQUIRED');
            }
            $this->createCustodyMovement($receipt, $sender, 'Handover Sent', false, $confirmedMinor, $recipient->name, $source->id);
            $this->createCustodyMovement($receipt, $recipient, 'Handover Received', true, $confirmedMinor, $sender->name, $source->id);
        } elseif ($confirmedMinor > 0 && $managerTransfer) {
            if (! $recipient || ! $destination) {
                throw new ConflictHttpException('RECEIVING_CASHIER_REQUIRED');
            }
            /** @var BranchManagerShift $managerShift */
            $managerShift = $source;
            PersonalLedgerTransaction::create([
                'branch_manager_id' => $managerShift->branch_manager_id,
                'transaction_type' => TransactionType::HANDOVER_TO_CASHIER->value,
                'amount' => self::toSar($confirmedMinor),
                'is_cash_in' => false,
                'cashier_name' => $recipient->name,
                'related_shift_id' => $managerShift->id,
                'receipt_id' => $receipt->id,
                'transaction_date' => now(),
            ]);
            $this->createCustodyMovement($receipt, $recipient, 'Handover Received', true, $confirmedMinor, $managerShift->branchManager?->name, null);
            $managerTransfer->update(['status' => 'confirmed']);
        } else {
            $managerTransfer?->update(['status' => 'confirmed']);
        }

        if ($handover && ! $managerDestination) {
            if (! $recipient || ! $destination) {
                throw new ConflictHttpException('RECEIVING_CASHIER_REQUIRED');
            }
            $handover->update([
                'status' => 'approved',
                'approved_by_id' => $recipient->id,
                'approved_by_type' => Cashier::class,
                'approved_at' => now(),
            ]);
            ShiftHandoverStatus::query()->where('cashier_shift_id', $handover->cashier_shift_id)->firstOrFail()
                ->approve($recipient->id, Cashier::class, $comment);
        }

        if ($destination) {
            $openingMinor = self::toMinorUnits((string) CashierShiftHandoverReceipt::query()
                ->where('receiving_cashier_shift_id', $destination->id)
                ->sum('confirmed_amount'));
            $destination->update(['opening_balance' => self::toSar($openingMinor)]);
        }

        $eventData = [
            'receipt_id' => $receipt->id,
            'confirmed_amount' => self::toSar($confirmedMinor),
            'receiving_cashier_shift_id' => $destination?->id,
            'receiving_branch_manager_shift_id' => $managerDestination?->id,
            'report_revision_id' => $revisionId,
            'confirmed_by_id' => $recipient?->id ?? $managerDestination?->branch_manager_id,
            'confirmed_by_type' => $recipient ? 'cashier' : 'branch_manager',
        ];
        if ($handover) {
            if ($managerDestination) {
                $managerAudit = new CashierShiftHistory;
                $managerAudit->forceFill([
                    'cashier_shift_id' => $source->id,
                    'action' => 'handover_confirmed',
                    'performed_by' => $managerDestination->branch_manager_id,
                    'performed_by_type' => 'branch_manager',
                    'old_value' => null,
                    'new_value' => $eventData + ['comment' => $comment],
                    'notes' => $comment,
                    'created_at' => now(),
                ])->save();
            } else {
                $source->recordHistory('handover_confirmed', null, $eventData + ['comment' => $comment]);
            }
        }
        $history = new CashierShiftHistory;
        $history->forceFill([
            'cashier_shift_id' => $destination?->id ?? $source->id,
            'action' => 'cash_transfer_received',
            'performed_by' => $recipient?->id ?? $managerDestination?->branch_manager_id,
            'performed_by_type' => $recipient ? 'cashier' : 'branch_manager',
            'old_value' => null,
            'new_value' => $eventData,
            'notes' => $comment,
            'created_at' => now(),
        ])->save();

        return $receipt;
    }

    private function resolveReceivingShift(CashierShift $source, Cashier $recipient, ?string $receivingShiftId): CashierShift
    {
        if ($receivingShiftId) {
            return CashierShift::query()->whereKey($receivingShiftId)->firstOrFail();
        }

        $matches = CashierShift::query()
            ->where('cashier_id', $recipient->id)
            ->whereDate('shift_date', $source->shift_date)
            ->whereIn('status', ['not_started', 'in_progress'])
            ->whereHas('shift', fn ($query) => $query->where('branch_id', $source->shift?->branch_id))
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            throw new ConflictHttpException('RECEIVING_SHIFT_ID_REQUIRED');
        }

        return $matches->first();
    }

    /** @return array{CashierShift, CashierShift} source then destination */
    private function lockCashierShiftsInOrder(string $sourceId, string $destinationId): array
    {
        if ($sourceId === $destinationId) {
            throw new ConflictHttpException('RECEIVING_SHIFT_NOT_AVAILABLE');
        }

        $ids = [$sourceId, $destinationId];
        sort($ids, SORT_STRING);
        $locked = [];

        foreach ($ids as $id) {
            $locked[$id] = CashierShift::withoutEagerLoads()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return [$locked[$sourceId], $locked[$destinationId]];
    }

    private function assertReceivingShift(CashierShift $source, CashierShift $destination, Cashier $recipient): void
    {
        $this->assertReceivingShiftBranch($source->shift?->branch_id, $destination, $recipient);
    }

    private function assertReceivingShiftBranch(?string $branchId, CashierShift $destination, Cashier $recipient): void
    {
        $destinationBranchId = $destination->shift?->branch_id;
        if ((string) $destination->cashier_id !== (string) $recipient->id
            || (string) $destinationBranchId !== (string) $branchId
            || ! in_array((string) $destination->getRawOriginal('status'), ['not_started', 'in_progress'], true)) {
            throw new ConflictHttpException('RECEIVING_SHIFT_NOT_AVAILABLE');
        }
    }

    /** Confirmed personal-ledger cash less all pending manager transfer requests. */
    private function availableManagerCashMinor(string $managerId): int
    {
        $ledgerMinor = PersonalLedgerTransaction::query()
            ->where('branch_manager_id', $managerId)
            // Liability/variance credits are claims, not confirmed sales cash.
            ->where(fn ($query) => $query->where('is_cash_in', false)->orWhere('transaction_type', 'Total Sales'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['amount', 'is_cash_in'])
            ->sum(fn (PersonalLedgerTransaction $row) => ($row->is_cash_in ? 1 : -1) * self::toMinorUnits((string) $row->amount));

        $reservedMinor = self::toMinorUnits((string) BranchManagerCashTransfer::query()
            ->whereHas('sourceWorkday', fn ($query) => $query->where('branch_manager_id', $managerId))
            ->where('status', 'pending')
            ->sum('requested_amount'));

        return max(0, $ledgerMinor - $reservedMinor);
    }

    private function createCustodyMovement(CashierShiftHandoverReceipt $receipt, Cashier $cashier, string $type, bool $cashIn, int $minor, ?string $counterpart, ?string $sourceShiftId): void
    {
        CashierCustodyTransaction::create([
            'cashier_id' => $cashier->id,
            'transaction_type' => $type,
            'amount' => self::toSar($minor),
            'is_cash_in' => $cashIn,
            'counterpart_name' => $counterpart,
            'related_shift_id' => $sourceShiftId,
            'related_handover_id' => $receipt->cashier_shift_handover_id,
            'receipt_id' => $receipt->id,
            'transaction_date' => now(),
        ]);
    }

    private function writeTransferCorrectionHistory(CashierShift $shift, string $actorId, string $actorType, string $action, array $details): void
    {
        $details['actor_id'] = $actorId;
        $details['actor_type'] = $actorType;
        $history = new CashierShiftHistory;
        $history->forceFill([
            'cashier_shift_id' => $shift->id,
            'action' => $action,
            'performed_by' => $actorId,
            'performed_by_type' => $actorType,
            'old_value' => null,
            'new_value' => $details,
            'notes' => $details['rejection_reason'] ?? $details['evidence_note'] ?? null,
            'created_at' => now(),
        ])->save();
    }

    private function assertCorrectionReason(string $reason): void
    {
        if (! in_array($reason, ['input_error', 'actual_shortage'], true)) {
            throw ValidationException::withMessages(['correction_reason' => 'Use input_error or actual_shortage.']);
        }
    }

    private static function toMinorUnits(string $amount): int
    {
        if (! preg_match('/\A\d+(?:\.\d{1,2})?\z/', $amount)) {
            throw ValidationException::withMessages(['amount' => 'Amount must be a nonnegative SAR value with at most two decimals.']);
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private static function toSar(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
