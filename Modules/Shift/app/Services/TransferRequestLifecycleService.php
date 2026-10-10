<?php

namespace Modules\Shift\Services;

use App\Support\ShiftFinancialCalculator;
use App\Support\ShiftMoneyValidation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftTransferAttempt;
use Modules\Shift\Models\ShiftTransferRejectionEvidence;
use Modules\Shift\Models\ShiftTransferReturn;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class TransferRequestLifecycleService
{
    public function __construct(
        private ShiftReportRevisionService $revisions,
        private ShiftCashCountService $cashCounts,
        private ShiftTransferReceiptService $receipts,
        private ShiftReportRevisionSnapshotService $snapshots,
        private ShiftTransferAttemptService $attemptService
    ) {}

    public function replaceRecipient(
        string $requestType,
        string $requestId,
        Model $actor,
        array $destination,
        string $amountSar,
        int $expectedRevision,
        string $reason,
        string $operationId
    ): Model {
        if (! in_array($requestType, ['handover', 'manager_transfer'], true)) {
            throw new \InvalidArgumentException("Invalid request type: {$requestType}");
        }

        if (trim($reason) === '') {
            throw new UnprocessableEntityHttpException('CANCELLATION_REASON_REQUIRED');
        }
        Validator::make(['amount' => $amountSar, 'operation_id' => $operationId], [
            'amount' => 'required|'.ShiftMoneyValidation::SAR,
            'operation_id' => 'required|string|max:255',
        ])->validate();
        $amountSar = sprintf('%d.%02d', intdiv(ShiftFinancialCalculator::sarToHalalas($amountSar), 100), ShiftFinancialCalculator::sarToHalalas($amountSar) % 100);

        return DB::transaction(function () use (
            $requestType,
            $requestId,
            $actor,
            $destination,
            $amountSar,
            $expectedRevision,
            $reason,
            $operationId
        ) {
            if ($requestType === 'handover') {
                return $this->replaceHandoverRecipient(
                    $requestId,
                    $actor,
                    $destination,
                    $amountSar,
                    $expectedRevision,
                    $reason,
                    $operationId
                );
            }

            return $this->replaceManagerTransferRecipient(
                $requestId,
                $actor,
                $destination,
                $amountSar,
                $expectedRevision,
                $reason,
                $operationId
            );
        });
    }

    private function replaceHandoverRecipient(
        string $requestId,
        Model $actor,
        array $destination,
        string $amountSar,
        int $expectedRevision,
        string $reason,
        string $operationId
    ): CashierShiftHandover {
        $stub = CashierShiftHandover::findOrFail($requestId);
        $snapshot = CashierShift::withoutEagerLoads()->findOrFail($stub->cashier_shift_id);
        $destination = $this->resolveDestination($snapshot, $destination);
        $cashierIds = ShiftTransferRejectionEvidence::where('cashier_shift_handover_id', $requestId)
            ->whereNotNull('receiving_cashier_shift_id')->pluck('receiving_cashier_shift_id')->all();
        if ($stub->current_transfer_attempt_id) {
            $cashierIds[] = ShiftTransferAttempt::whereKey($stub->current_transfer_attempt_id)->value('receiving_cashier_shift_id');
        }
        if ($stub->handover_to_type === 'cashier') {
            $cashierIds = array_merge($cashierIds, CashierShift::where('cashier_id', $stub->handover_to_id)
                ->whereDate('shift_date', $snapshot->shift_date)->whereIn('status', ['not_started', 'in_progress'])
                ->pluck('id')->all());
        }
        $cashierIds[] = $destination['receiving_shift_id'] ?? $destination['destination_cashier_shift_id'] ?? null;
        $sourceShift = app(ShiftReportMutationGuard::class)->lockEditable($snapshot, $actor, $cashierIds);
        $oldHandover = CashierShiftHandover::whereKey($requestId)->lockForUpdate()->firstOrFail();

        // Guard active state and receipt
        TransferRequestLifecycleGuard::assertActive($oldHandover);
        if ($oldHandover->status === 'approved' || $oldHandover->receipt()->lockForUpdate()->first()) {
            throw new ConflictHttpException('CONFIRMED_RECEIPT_IMMUTABLE');
        }

        // Actor check
        if (! ($actor instanceof Cashier && (string) $actor->id === (string) $sourceShift->cashier_id)) {
            $branchId = $sourceShift->shift()->value('branch_id');
            if (! ($actor instanceof BranchManager && (string) $actor->branch_id === (string) $branchId)) {
                throw new AccessDeniedHttpException('ONLY_TRANSFER_OWNER_OR_BRANCH_MANAGER');
            }
        }

        // Revision check
        $currentRevision = $this->revisions->currentCashierRevision($sourceShift);
        if (! $currentRevision || (int) $currentRevision->revision_number !== $expectedRevision) {
            throw new ConflictHttpException('STALE_REPORT_REVISION');
        }

        $this->assertCashReturned('handover', $oldHandover);

        // Destination resolution
        $newRecipientType = $destination['recipient_type'] ?? $destination['handover_to_type'] ?? 'cashier';
        $newRecipientId = $destination['recipient_id'] ?? $destination['handover_to_id'] ?? null;
        if (! $newRecipientId) {
            throw new UnprocessableEntityHttpException('DESTINATION_RECIPIENT_REQUIRED');
        }
        if ($newRecipientType === $oldHandover->handover_to_type && (string) $newRecipientId === (string) $oldHandover->handover_to_id) {
            throw new ConflictHttpException('HANDOVER_RECIPIENT_UNCHANGED');
        }

        $branchId = $sourceShift->shift()->value('branch_id');
        $destShift = null;
        if ($newRecipientType === 'cashier') {
            $newCashier = Cashier::findOrFail($newRecipientId);
            if ((string) $newCashier->branch_id !== (string) $branchId) {
                throw new AccessDeniedHttpException('CROSS_BRANCH_TRANSFER_PROHIBITED');
            }
            $destShiftId = $destination['receiving_shift_id'] ?? $destination['destination_cashier_shift_id'] ?? null;
            if ($destShiftId) {
                $destShift = CashierShift::withoutEagerLoads()->whereKey($destShiftId)->lockForUpdate()->firstOrFail();
            } else {
                $destShift = CashierShift::withoutEagerLoads()
                    ->where('cashier_id', $newRecipientId)
                    ->whereHas('shift', fn ($q) => $q->where('branch_id', $branchId))
                    ->whereDate('shift_date', $sourceShift->shift_date)
                    ->whereIn('status', [ShiftStatus::NOT_STARTED, ShiftStatus::IN_PROGRESS])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
            }
            if (! $destShift
                || (string) $destShift->cashier_id !== (string) $newRecipientId
                || (string) $destShift->shift()->value('branch_id') !== (string) $branchId
                || ! in_array((string) $destShift->getRawOriginal('status'), ['not_started', 'in_progress'], true)) {
                throw new ConflictHttpException('RECEIVING_SHIFT_NOT_AVAILABLE');
            }
        } elseif ($newRecipientType === 'branch_manager') {
            $newManager = app(\Modules\BranchManagers\Services\BranchManagerService::class)
                ->assertAssignedActiveManager($branchId, $newRecipientId);
            if ((string) $newManager->branch_id !== (string) $branchId) {
                throw new AccessDeniedHttpException('CROSS_BRANCH_TRANSFER_PROHIBITED');
            }
        } else {
            throw new UnprocessableEntityHttpException('INVALID_RECIPIENT_TYPE');
        }

        // Preserve complete before-state even if a pre-existing snapshot lacks workflow fields.
        $this->snapshots->preserveVarianceReviews($sourceShift, $currentRevision);
        $this->snapshots->createSnapshotIfMissing($currentRevision, $sourceShift);
        $previousState = [
            'previous_handover_id' => $oldHandover->id,
            'request' => $oldHandover->toArray(),
            'workflow' => $sourceShift->handoverStatus?->toArray(),
            'report_projection' => $sourceShift->attributesToArray(),
        ];

        // Cancel old handover
        $oldHandover->update([
            'cancelled_at' => now(),
            'cancelled_by_type' => $actor->getMorphClass(),
            'cancelled_by_id' => (string) $actor->getKey(),
            'cancellation_reason' => $reason,
            'superseded_at' => now(),
        ]);

        // Advance revision and carry forward count
        $newRevision = $this->revisions->recordCashierRevision($sourceShift, $actor->getMorphClass(), (string) $actor->getKey());
        $this->cashCounts->carryForward($currentRevision, $newRevision);

        // Create replacement handover
        $replacement = CashierShiftHandover::create([
            'cashier_shift_id' => $sourceShift->id,
            'handover_to_type' => $newRecipientType,
            'handover_to_id' => $newRecipientId,
            'handover_amount' => $amountSar,
            'variance_amount' => $oldHandover->variance_amount,
            'variance_reason' => $oldHandover->variance_reason,
            'variance_files' => $oldHandover->variance_files,
            'handover_notes' => $oldHandover->handover_notes,
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'pending',
            'report_revision_id' => $newRevision->id,
            'supersedes_id' => $oldHandover->id,
        ]);

        $oldHandover->update(['replacement_request_id' => $replacement->id]);

        $sourceShift->update([
            'next_cashier_id' => $newRecipientType === 'cashier' ? $newRecipientId : null,
        ]);
        $sourceShift->unsetRelation('handover');
        $sourceShift->unsetRelation('nextCashier');

        ShiftHandoverStatus::updateOrCreate(
            ['cashier_shift_id' => $sourceShift->id],
            [
                'status' => 'pending',
                'rejection_count' => 0,
                'manager_approval_status' => 'pending',
                'reviewed_by_id' => null,
                'reviewed_by_type' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
                'rejection_files' => null,
                'manager_comment' => null,
                'first_rejected_at' => null,
                'second_rejected_at' => null,
                'was_edited_after_rejection' => false,
                'edited_at' => null,
            ]
        );

        $this->snapshots->createSnapshotIfMissing($newRevision, $sourceShift);

        $sourceShift->history()->create([
            'action' => 'handover_recipient_replaced',
            'performed_by' => (string) $actor->getKey(),
            'performed_by_type' => $actor->getMorphClass(),
            'old_value' => $previousState,
            'new_value' => [
                'replacement_handover_id' => $replacement->id,
                'old_recipient_type' => $oldHandover->handover_to_type,
                'old_recipient_id' => $oldHandover->handover_to_id,
                'new_recipient_type' => $newRecipientType,
                'new_recipient_id' => $newRecipientId,
                'amount' => $amountSar,
                'reason' => $reason,
                'operation_id' => $operationId,
            ],
            'notes' => $reason,
        ]);

        app(ShiftReportCacheInvalidator::class)->cashierReport($sourceShift);

        return $replacement;
    }

    private function resolveDestination(CashierShift $source, array $destination): array
    {
        $type = $destination['recipient_type'] ?? $destination['handover_to_type'] ?? 'cashier';
        $id = $destination['recipient_id'] ?? $destination['handover_to_id'] ?? null;
        if ($type !== 'cashier' || ! $id || ! empty($destination['receiving_shift_id']) || ! empty($destination['destination_cashier_shift_id'])) {
            return $destination;
        }
        $ids = CashierShift::where('cashier_id', $id)->whereDate('shift_date', $source->shift_date)
            ->whereHas('shift', fn ($query) => $query->where('branch_id', $source->shift()->value('branch_id')))
            ->whereIn('status', ['not_started', 'in_progress'])->orderBy('id')->limit(2)->pluck('id');
        if ($ids->count() !== 1) {
            throw new ConflictHttpException('RECEIVING_SHIFT_ID_REQUIRED');
        }
        $destination['receiving_shift_id'] = $ids->first();

        return $destination;
    }

    private function replaceManagerTransferRecipient(
        string $requestId,
        Model $actor,
        array $destination,
        string $amountSar,
        int $expectedRevision,
        string $reason,
        string $operationId
    ): BranchManagerCashTransfer {
        $stub = BranchManagerCashTransfer::findOrFail($requestId);
        $this->receipts->lockManagerCashOwner((string) BranchManagerShift::whereKey($stub->branch_manager_shift_id)->value('branch_manager_id'));
        $sourceWorkday = BranchManagerShift::whereKey($stub->branch_manager_shift_id)->lockForUpdate()->firstOrFail();
        $oldTransfer = BranchManagerCashTransfer::whereKey($requestId)->lockForUpdate()->firstOrFail();

        TransferRequestLifecycleGuard::assertActive($oldTransfer);
        if ($oldTransfer->status === 'approved' || $oldTransfer->receipt()->lockForUpdate()->first()) {
            throw new ConflictHttpException('CONFIRMED_RECEIPT_IMMUTABLE');
        }

        if (! ($actor instanceof BranchManager && (string) $actor->id === (string) $sourceWorkday->branch_manager_id)) {
            throw new AccessDeniedHttpException('ONLY_SOURCE_BRANCH_MANAGER');
        }

        $currentRevision = $this->revisions->currentManagerRevision($sourceWorkday);
        if (! $currentRevision || (int) $currentRevision->revision_number !== $expectedRevision) {
            throw new ConflictHttpException('STALE_REPORT_REVISION');
        }

        $this->assertCashReturned('manager_transfer', $oldTransfer);

        $newCashierId = $destination['recipient_id'] ?? $destination['destination_cashier_id'] ?? null;
        if (! $newCashierId) {
            throw new UnprocessableEntityHttpException('DESTINATION_CASHIER_REQUIRED');
        }
        if ((string) $newCashierId === (string) $oldTransfer->destination_cashier_id) {
            throw new ConflictHttpException('HANDOVER_RECIPIENT_UNCHANGED');
        }

        $newCashier = Cashier::findOrFail($newCashierId);
        if ((string) $newCashier->branch_id !== (string) $sourceWorkday->branch_id) {
            throw new AccessDeniedHttpException('CROSS_BRANCH_TRANSFER_PROHIBITED');
        }

        $destShiftId = $destination['destination_cashier_shift_id'] ?? $destination['receiving_shift_id'] ?? null;
        if ($destShiftId) {
            $destShift = CashierShift::withoutEagerLoads()->whereKey($destShiftId)->lockForUpdate()->firstOrFail();
        } else {
            $destinations = CashierShift::withoutEagerLoads()
                ->where('cashier_id', $newCashierId)
                ->whereHas('shift', fn ($q) => $q->where('branch_id', $sourceWorkday->branch_id))
                ->whereDate('shift_date', $sourceWorkday->shift_date)
                ->whereIn('status', [ShiftStatus::NOT_STARTED, ShiftStatus::IN_PROGRESS])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($destinations->count() !== 1) {
                throw new ConflictHttpException('RECEIVING_SHIFT_ID_REQUIRED');
            }
            $destShift = $destinations->first();
        }

        if (! $destShift
            || (string) $destShift->cashier_id !== (string) $newCashierId
            || (string) $destShift->shift()->value('branch_id') !== (string) $sourceWorkday->branch_id
            || ! in_array((string) $destShift->getRawOriginal('status'), ['not_started', 'in_progress'], true)) {
            throw new ConflictHttpException('RECEIVING_SHIFT_NOT_AVAILABLE');
        }

        // Validate available cash
        $requestedMinor = ShiftFinancialCalculator::sarToHalalas($amountSar);
        $oldReservedMinor = ($oldTransfer->status === 'pending' && ! $oldTransfer->isCancelled())
            ? ShiftFinancialCalculator::storedSarToHalalas((string) $oldTransfer->requested_amount)
            : 0;

        $availableMinor = $this->receipts->availableManagerCashMinor($sourceWorkday->branch_manager_id) + $oldReservedMinor;
        if ($requestedMinor > $availableMinor) {
            throw new ConflictHttpException('INSUFFICIENT_RECORDED_SALES_CASH');
        }

        $this->snapshots->createSnapshotIfMissing($currentRevision, $sourceWorkday);

        // Cancel old transfer
        $oldTransfer->update([
            'cancelled_at' => now(),
            'cancelled_by_type' => $actor->getMorphClass(),
            'cancelled_by_id' => (string) $actor->getKey(),
            'cancellation_reason' => $reason,
            'superseded_at' => now(),
        ]);

        $replacement = BranchManagerCashTransfer::create([
            'branch_manager_shift_id' => $sourceWorkday->id,
            'destination_cashier_id' => $newCashier->id,
            'destination_cashier_shift_id' => $destShift->id,
            'report_revision_id' => $currentRevision->id,
            'created_by_id' => (string) $actor->getKey(),
            'requested_amount' => $amountSar,
            'status' => 'pending',
            'supersedes_id' => $oldTransfer->id,
        ]);

        $oldTransfer->update(['replacement_request_id' => $replacement->id]);
        app(ShiftReportCacheInvalidator::class)->branch((string) $sourceWorkday->branch_id);

        return $replacement;
    }

    private function assertCashReturned(string $type, Model $request): void
    {
        $field = $type === 'handover' ? 'cashier_shift_handover_id' : 'branch_manager_cash_transfer_id';
        $legacy = ShiftTransferRejectionEvidence::where($field, $request->id)
            ->whereNull('transfer_attempt_id')->orderByDesc('rejected_at')
            ->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();
        if ($legacy && $legacy->physical_halalas > 0) {
            throw new ConflictHttpException('PHYSICAL_RETURN_REQUIRED');
        }

        $attempts = ShiftTransferAttempt::where('request_type', $type)->where('request_id', $request->id)
            ->orderBy('id')->lockForUpdate()->get();
        if ($request->current_transfer_attempt_id && ! $attempts->contains('id', $request->current_transfer_attempt_id)) {
            throw new ConflictHttpException('PHYSICAL_RETURN_REQUIRED');
        }
        foreach ($attempts as $attempt) {
            if (ShiftTransferRejectionEvidence::where('transfer_attempt_id', $attempt->id)->lockForUpdate()->doesntExist()) {
                throw new ConflictHttpException('STALE_TRANSFER_ATTEMPT');
            }
            if ($this->attemptService->retained($attempt) > 0
                || ShiftTransferReturn::where('transfer_attempt_id', $attempt->id)->whereNull('sender_confirmed_at')->lockForUpdate()->exists()) {
                throw new ConflictHttpException('PHYSICAL_RETURN_REQUIRED');
            }
        }
    }
}
