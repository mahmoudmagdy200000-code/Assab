<?php

namespace Modules\Shift\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use App\Support\ShiftFinancialCalculator;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\CashierShiftHistory;
use Modules\Shift\Models\ShiftDebtRepayment;
use Modules\Shift\Models\ShiftHandoverReversal;
use Modules\Shift\Models\ShiftHandoverSettlement;
use Modules\Shift\Policies\HandoverSettlementActorPolicy;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * S1-11 R6: Authoritative service executing additive settlements on confirmed handover receipts.
 * Maintains confirmed receipt immutability while atomically writing dual counterpart ledger rows.
 */
class HandoverSettlementService
{
    public const MAX_MONETARY_HALALAS = 999999999999;

    public function __construct(
        private ?HandoverSettlementActorPolicy $policy = null
    ) {
        $this->policy = $policy ?? app(HandoverSettlementActorPolicy::class);
    }

    /**
     * Settle a confirmed transfer receipt with an additive overpayment return or underpayment supplement.
     *
     * @throws ValidationException|ConflictHttpException|AccessDeniedHttpException
     */
    public function settle(
        CashierShiftHandoverReceipt $receipt,
        Model $actor,
        string $type,
        int $amountHalalas,
        int $expectedSettlementVersion,
        string $reason,
        string $operationId
    ): ShiftHandoverSettlement {
        $this->assertPayload($type, $amountHalalas, $reason, $operationId);

        return DB::transaction(function () use ($receipt, $actor, $type, $amountHalalas, $expectedSettlementVersion, $reason, $operationId) {
            // 1. Resolve provenance from receipt
            $receipt = CashierShiftHandoverReceipt::whereKey($receipt->id)->firstOrFail();
            $provenance = $this->resolveProvenance($receipt);

            // 2. Assert actor authority
            $this->policy->assertCanSettle($actor, $receipt, $provenance['branch_id']);

            // 3. Acquire manager cash-owner mutexes in deterministic sorted order
            $managerIds = array_unique(array_filter([
                $provenance['sender_type'] === 'branch_manager' ? $provenance['sender_id'] : null,
                $provenance['recipient_type'] === 'branch_manager' ? $provenance['recipient_id'] : null,
                $actor instanceof BranchManager ? (string) $actor->id : null,
            ]));
            sort($managerIds, SORT_STRING);
            foreach ($managerIds as $mId) {
                BranchManager::whereKey($mId)->lockForUpdate()->firstOrFail();
            }

            app(ShiftCashAvailabilityService::class)->lockCashierOwners(array_filter([
                $provenance['sender_type'] === 'cashier' ? $provenance['sender_id'] : null,
                $provenance['recipient_type'] === 'cashier' ? $provenance['recipient_id'] : null,
            ]));

            // Shared order: cash owners, manager workday, sorted cashier shifts,
            // source request, physical attempt, immutable receipt, settlements.
            if ($provenance['manager_workday_id']) {
                BranchManagerShift::whereKey($provenance['manager_workday_id'])->lockForUpdate()->first();
            }
            $shiftIds = array_values(array_unique(array_filter([$provenance['source_shift_id'], $provenance['destination_shift_id']])));
            sort($shiftIds, SORT_STRING);
            foreach ($shiftIds as $shiftId) {
                CashierShift::withoutEagerLoads()->whereKey($shiftId)->lockForUpdate()->firstOrFail();
            }
            $requestClass = $provenance['request_type'] === 'handover' ? CashierShiftHandover::class : BranchManagerCashTransfer::class;
            $requestClass::whereKey($provenance['request_id'])->lockForUpdate()->firstOrFail();
            if ($receipt->transfer_attempt_id !== null) {
                \Modules\Shift\Models\ShiftTransferAttempt::whereKey($receipt->transfer_attempt_id)->lockForUpdate()->firstOrFail();
            }
            $receipt = CashierShiftHandoverReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $freshProvenance = $this->resolveProvenance($receipt);
            if ($freshProvenance !== $provenance) {
                throw new ConflictHttpException('UNRESOLVED_RECEIPT_PROVENANCE');
            }
            $this->policy->assertCanSettle($actor, $receipt, $provenance['branch_id']);

            // 5. Query and lock existing settlements on this receipt
            $existingSettlements = ShiftHandoverSettlement::where('original_receipt_id', $receipt->id)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            // 6. Check durable operation idempotency under lock BEFORE version check
            $canonicalPayload = [
                'receipt_id' => (string) $receipt->id,
                'settlement_type' => $type,
                'amount_halalas' => $amountHalalas,
                'expected_settlement_version' => $expectedSettlementVersion,
                'reason' => $reason,
                'actor_type' => $actor instanceof BranchManager ? 'branch_manager' : ($actor instanceof Cashier ? 'cashier' : 'asab_user'),
                'actor_id' => (string) $actor->id,
            ];
            $canonicalHash = hash('sha256', json_encode($canonicalPayload, JSON_THROW_ON_ERROR));
            $existingOp = $existingSettlements->firstWhere('operation_id', $operationId);

            if ($existingOp !== null) {
                if ($existingOp->payload_hash !== $canonicalHash) {
                    throw new ConflictHttpException('IDEMPOTENCY_PAYLOAD_MISMATCH');
                }

                return $existingOp;
            }

            // 7. Verify expected settlement version
            $currentVersion = $existingSettlements->count();
            if ($currentVersion !== $expectedSettlementVersion) {
                throw new ConflictHttpException('STALE_SETTLEMENT_VERSION');
            }

            // 8. Validate amount against limits and availability
            $currentEffectiveHalalas = ShiftCashAvailabilityService::minor((string) $receipt->confirmed_amount);
            foreach ($existingSettlements as $previousSettlement) {
                $currentEffectiveHalalas = ShiftCashAvailabilityService::add($currentEffectiveHalalas, (int) $previousSettlement->signed_delta_halalas);
            }

            if ($type === 'overpayment_return') {
                $signedDeltaHalalas = -$amountHalalas;
                if ($amountHalalas > $currentEffectiveHalalas) {
                    throw new ConflictHttpException('OVERPAYMENT_RETURN_EXCEEDS_CONFIRMED_AMOUNT');
                }

                // Cash-out party is the recipient returning excess cash
                if ($provenance['recipient_type'] === 'branch_manager') {
                    $availableMinor = app(ShiftTransferReceiptService::class)->availableManagerCashMinor($provenance['recipient_id']);
                    if ($amountHalalas > $availableMinor) {
                        throw new ConflictHttpException('INSUFFICIENT_RECORDED_SALES_CASH');
                    }
                } elseif ($provenance['recipient_type'] === 'cashier') {
                    $balanceHalalas = app(ShiftCashAvailabilityService::class)->availableCashierMinor($provenance['recipient_id']);
                    if ($amountHalalas > $balanceHalalas) {
                        throw new ConflictHttpException('INSUFFICIENT_CASHIER_CUSTODY_CASH');
                    }
                }
            } else {
                $signedDeltaHalalas = +$amountHalalas;
                if (ShiftCashAvailabilityService::add($currentEffectiveHalalas, $amountHalalas) > self::MAX_MONETARY_HALALAS) {
                    throw new ConflictHttpException('MONETARY_LIMIT_EXCEEDED');
                }

                // Cash-out party is the sender providing supplement cash
                if ($provenance['sender_type'] === 'branch_manager') {
                    $availableMinor = app(ShiftTransferReceiptService::class)->availableManagerCashMinor($provenance['sender_id']);
                    if ($amountHalalas > $availableMinor) {
                        throw new ConflictHttpException('INSUFFICIENT_RECORDED_SALES_CASH');
                    }
                } elseif ($provenance['sender_type'] === 'cashier') {
                    $balanceHalalas = app(ShiftCashAvailabilityService::class)->availableCashierMinor($provenance['sender_id']);
                    if ($amountHalalas > $balanceHalalas) {
                        throw new ConflictHttpException('INSUFFICIENT_CASHIER_CUSTODY_CASH');
                    }
                }
            }

            // 9. Actor morph type
            $actorType = $actor instanceof BranchManager ? 'branch_manager' : ($actor instanceof Cashier ? 'cashier' : 'asab_user');

            // 10. Create immutable settlement evidence record
            $settlement = ShiftHandoverSettlement::create([
                'original_receipt_id' => $receipt->id,
                'original_attempt_id' => $receipt->transfer_attempt_id,
                'original_request_type' => $provenance['request_type'],
                'original_request_id' => $provenance['request_id'],
                'settlement_type' => $type,
                'signed_delta_halalas' => $signedDeltaHalalas,
                'reason' => $reason,
                'operation_id' => $operationId,
                'payload_hash' => $canonicalHash,
                'sender_type' => $provenance['sender_type'],
                'sender_id' => $provenance['sender_id'],
                'recipient_type' => $provenance['recipient_type'],
                'recipient_id' => $provenance['recipient_id'],
                'actor_type' => $actorType,
                'actor_id' => $actor->id,
                'company_id' => $provenance['company_id'],
                'branch_id' => $provenance['branch_id'],
            ]);

            // 11. Create dual counterpart ledger rows
            $sarUnits = intdiv($amountHalalas, 100);
            $sarFraction = abs($amountHalalas % 100);
            $amountSar = sprintf('%d.%02d', $sarUnits, $sarFraction);

            if ($type === 'overpayment_return') {
                // Sender gets excess cash returned (+in)
                // Recipient returns excess cash (-out)
                $senderIsCashIn = true;
                $recipientIsCashIn = false;
                $senderTxType = 'Settlement In';
                $recipientTxType = 'Settlement Out';
            } else {
                // Sender disburses deficit (-out)
                // Recipient receives supplement (+in)
                $senderIsCashIn = false;
                $recipientIsCashIn = true;
                $senderTxType = 'Settlement Out';
                $recipientTxType = 'Settlement In';
            }

            // Write Sender ledger row
            $this->createLedgerRow(
                $provenance['sender_type'],
                $provenance['sender_id'],
                $settlement->id,
                $senderTxType,
                $amountSar,
                $senderIsCashIn,
                $provenance['recipient_name'],
                $provenance['source_shift_id'],
                $provenance['request_id']
            );

            // Write Recipient ledger row
            $this->createLedgerRow(
                $provenance['recipient_type'],
                $provenance['recipient_id'],
                $settlement->id,
                $recipientTxType,
                $amountSar,
                $recipientIsCashIn,
                $provenance['sender_name'],
                $provenance['destination_shift_id'] ?? $provenance['manager_workday_id'],
                $provenance['request_id']
            );

            // 12. Write history audit record
            if ($provenance['source_shift_id']) {
                $history = new CashierShiftHistory;
                $history->forceFill([
                    'cashier_shift_id' => $provenance['source_shift_id'],
                    'action' => 'handover_settled',
                    'performed_by' => $actor->id,
                    'performed_by_type' => $actorType,
                    'old_value' => null,
                    'new_value' => [
                        'settlement_id' => $settlement->id,
                        'original_receipt_id' => $receipt->id,
                        'settlement_type' => $type,
                        'amount_halalas' => $amountHalalas,
                        'signed_delta_halalas' => $signedDeltaHalalas,
                        'reason' => $reason,
                        'operation_id' => $operationId,
                    ],
                    'notes' => $reason,
                    'created_at' => now(),
                ])->save();
            }

            return $settlement;
        });
    }

    /**
     * Resolve historical provenance (request, sender, recipient, branch, company) from receipt.
     *
     * @return array{
     *     request_type: string,
     *     request_id: string,
     *     sender_type: string,
     *     sender_id: string,
     *     sender_name: string,
     *     recipient_type: string,
     *     recipient_id: string,
     *     recipient_name: string,
     *     branch_id: string,
     *     company_id: ?string,
     *     source_shift_id: ?string,
     *     destination_shift_id: ?string,
     *     manager_workday_id: ?string
     * }
     */
    public function resolveProvenance(CashierShiftHandoverReceipt $receipt): array
    {
        return app(ShiftReceiptProvenanceService::class)->resolve($receipt);
    }

    private function createLedgerRow(
        string $partyType,
        string $partyId,
        string $settlementId,
        string $txType,
        string $amountSar,
        bool $isCashIn,
        string $counterpartName,
        ?string $shiftId,
        string $requestId
    ): void {
        if ($partyType === 'cashier') {
            CashierCustodyTransaction::create([
                'cashier_id' => $partyId,
                'settlement_id' => $settlementId,
                'transaction_type' => $txType,
                'amount' => $amountSar,
                'is_cash_in' => $isCashIn,
                'counterpart_name' => $counterpartName,
                'related_shift_id' => $shiftId,
                'related_handover_id' => $requestId,
                'transaction_date' => now(),
                'category' => 'handover_settlement',
            ]);
        } elseif ($partyType === 'branch_manager') {
            PersonalLedgerTransaction::create([
                'branch_manager_id' => $partyId,
                'settlement_id' => $settlementId,
                'transaction_type' => $txType,
                'amount' => $amountSar,
                'is_cash_in' => $isCashIn,
                'cashier_name' => $counterpartName,
                'related_shift_id' => $shiftId,
                'related_handover_id' => $requestId,
                'transaction_date' => now(),
                'category' => 'handover_settlement',
            ]);
        }
    }

    private function assertPayload(string $type, int $amountHalalas, string $reason, string $operationId): void
    {
        if (! in_array($type, ['overpayment_return', 'underpayment_supplement'], true)) {
            throw ValidationException::withMessages(['settlement_type' => 'Settlement type must be overpayment_return or underpayment_supplement.']);
        }

        if ($amountHalalas <= 0 || $amountHalalas > self::MAX_MONETARY_HALALAS) {
            throw ValidationException::withMessages(['amount_halalas' => 'Amount in halalas must be a strictly positive integer within column limits.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Reason must not be empty.']);
        }

        if (trim($operationId) === '' || strlen($operationId) > 100) {
            throw ValidationException::withMessages(['operation_id' => 'Operation ID is required and must not exceed 100 characters.']);
        }
    }

    /**
     * CASH-14 / BE-13: Recipient initiates mutual two-party cash handover reversal dispute.
     * Transitions status to pending_sender_confirmation with zero money movement.
     *
     * @throws ValidationException|ConflictHttpException|AccessDeniedHttpException
     */
    public function initiateReversal(CashierShiftHandover $handover, Model $actor, string $reason): ShiftHandoverReversal
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Reversal reason is required.']);
        }

        return DB::transaction(function () use ($handover, $actor, $reason) {
            $handover = CashierShiftHandover::whereKey($handover->id)->lockForUpdate()->firstOrFail();

            // E04: Receipt must exist and be confirmed
            $receipt = $handover->receipt;
            if ($receipt === null || $receipt->confirmed_amount === null) {
                throw new ConflictHttpException('HANDOVER_RECEIPT_NOT_CONFIRMED');
            }

            // E05: Assert actor is the actual recipient from the confirmed receipt
            $actorId = (string) $actor->id;
            $isRecipient = false;
            if ($actor instanceof Cashier && $receipt->receiving_cashier_id !== null && $actorId === (string) $receipt->receiving_cashier_id) {
                $isRecipient = true;
            } elseif ($actor instanceof BranchManager && $receipt->receiving_branch_manager_id !== null && $actorId === (string) $receipt->receiving_branch_manager_id) {
                $isRecipient = true;
            } elseif ($actor instanceof Cashier && $handover->handover_to_type === 'cashier' && $actorId === (string) $handover->handover_to_id) {
                $isRecipient = true;
            } elseif ($actor instanceof BranchManager && $handover->handover_to_type === 'branch_manager' && ($actorId === (string) $handover->handover_to_id || $actorId === (string) $handover->receivingBranchManagerId())) {
                $isRecipient = true;
            }

            if (! $isRecipient) {
                throw new AccessDeniedHttpException('ONLY_ACTUAL_RECIPIENT_MAY_DISPUTE_REVERSAL');
            }

            // E04: Prevent creating a new reversal after an earlier reversal is pending or confirmed
            $existing = ShiftHandoverReversal::where('original_handover_id', $handover->id)
                ->whereIn('status', ['pending_sender_confirmation', 'confirmed'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status === 'confirmed') {
                    throw new ConflictHttpException('HANDOVER_ALREADY_REVERSED');
                }
                throw new ConflictHttpException('REVERSAL_ALREADY_PENDING');
            }

            $branchId = (string) ($handover->branch_id ?? $handover->cashierShift?->branch_id);
            $companyId = DB::table('branches')->where('id', $branchId)->value('asab_company_id');

            return ShiftHandoverReversal::create([
                'company_id' => $companyId ?? $branchId,
                'branch_id' => $branchId,
                'original_handover_id' => $handover->id,
                'original_receipt_id' => $receipt->id,
                'reversal_reason' => $reason,
                'initiated_by_user_id' => $actorId,
                'status' => 'pending_sender_confirmation',
            ]);
        });
    }

    /**
     * CASH-14 / BE-13: Original sender confirms two-party cash handover reversal.
     * Atomically transitions status to confirmed and posts dual counterpart compensating ledger rows.
     *
     * @return array{reversal: ShiftHandoverReversal, reversed_amount_halalas: int}
     *
     * @throws ConflictHttpException|AccessDeniedHttpException
     */
    public function confirmReversal(ShiftHandoverReversal $reversal, Model $actor): array
    {
        return DB::transaction(function () use ($reversal, $actor) {
            $reversal = ShiftHandoverReversal::whereKey($reversal->id)->lockForUpdate()->firstOrFail();

            if ($reversal->status !== 'pending_sender_confirmation') {
                throw new ConflictHttpException('REVERSAL_ALREADY_RESOLVED');
            }

            $handover = CashierShiftHandover::whereKey($reversal->original_handover_id)->lockForUpdate()->firstOrFail();

            // E04: Check if already confirmed by another path
            $priorConfirmed = ShiftHandoverReversal::where('original_handover_id', $handover->id)
                ->where('status', 'confirmed')
                ->where('id', '!=', $reversal->id)
                ->exists();
            if ($priorConfirmed) {
                throw new ConflictHttpException('HANDOVER_ALREADY_REVERSED');
            }

            // Assert actor is the original sender
            $senderId = (string) $handover->cashierShift?->cashier_id;
            $senderType = 'cashier';
            if ((string) $actor->id !== $senderId) {
                throw new AccessDeniedHttpException('ONLY_SENDER_MAY_CONFIRM_REVERSAL');
            }

            // E04 & E05: Amount and recipient strictly from confirmed receipt
            $receipt = $handover->receipt;
            if ($receipt === null || $receipt->confirmed_amount === null) {
                throw new ConflictHttpException('UNRESOLVED_RECEIPT_PROVENANCE');
            }

            $amountHalalas = ShiftFinancialCalculator::storedSarToHalalas($receipt->confirmed_amount);
            $sarUnits = intdiv($amountHalalas, 100);
            $sarFraction = abs($amountHalalas % 100);
            $amountSar = sprintf('%d.%02d', $sarUnits, $sarFraction);

            // E05: Actual recipient from confirmed receipt
            if ($receipt->receiving_branch_manager_id !== null) {
                $recipientType = 'branch_manager';
                $recipientId = (string) $receipt->receiving_branch_manager_id;
                $recipientName = $receipt->receivingBranchManager?->name ?? 'Branch Manager';
            } elseif ($receipt->receiving_cashier_id !== null) {
                $recipientType = 'cashier';
                $recipientId = (string) $receipt->receiving_cashier_id;
                $recipientName = $receipt->receivingCashier?->name ?? 'Cashier';
            } else {
                $recipientType = $handover->handover_to_type;
                $recipientId = (string) $handover->handover_to_id;
                $recipientName = $recipientType === 'cashier'
                    ? ($handover->handoverTo?->name ?? 'Cashier')
                    : ($handover->branchManager()?->name ?? 'Branch Manager');
            }

            $senderName = $handover->cashier()?->name ?? 'Cashier';

            // 1. Sender receives returned cash (+in)
            $this->createReversalLedgerRow(
                $senderType,
                $senderId,
                $reversal->id,
                'Reversal In',
                $amountSar,
                true,
                $recipientName,
                $handover->cashier_shift_id,
                $handover->id
            );

            // 2. Recipient disburses reversed cash back (-out)
            $this->createReversalLedgerRow(
                $recipientType,
                $recipientId,
                $reversal->id,
                'Reversal Out',
                $amountSar,
                false,
                $senderName,
                $receipt->receiving_cashier_shift_id ?? $receipt->receiving_branch_manager_shift_id,
                $handover->id
            );

            $reversal->update([
                'confirmed_by_user_id' => (string) $actor->id,
                'confirmed_at' => now(),
                'status' => 'confirmed',
            ]);

            return [
                'reversal' => $reversal,
                'reversed_amount_halalas' => $amountHalalas,
            ];
        });
    }

    /**
     * CASH-14 / BE-13: Original sender rejects two-party cash handover reversal.
     * Transitions status to rejected with zero money movement.
     *
     * @throws ConflictHttpException|AccessDeniedHttpException
     */
    public function rejectReversal(ShiftHandoverReversal $reversal, Model $actor, ?string $rejectionReason = null): ShiftHandoverReversal
    {
        return DB::transaction(function () use ($reversal, $actor) {
            $reversal = ShiftHandoverReversal::whereKey($reversal->id)->lockForUpdate()->firstOrFail();

            if ($reversal->status !== 'pending_sender_confirmation') {
                throw new ConflictHttpException('REVERSAL_ALREADY_RESOLVED');
            }

            $handover = CashierShiftHandover::whereKey($reversal->original_handover_id)->lockForUpdate()->firstOrFail();
            $senderId = (string) $handover->cashierShift?->cashier_id;
            if ((string) $actor->id !== $senderId) {
                throw new AccessDeniedHttpException('ONLY_SENDER_MAY_REJECT_REVERSAL');
            }

            $reversal->update([
                'status' => 'rejected',
            ]);

            return $reversal;
        });
    }

    /**
     * E06: Link subsequent corrected handover to confirmed reversal.
     */
    public function linkCorrectedHandover(ShiftHandoverReversal $reversal, CashierShiftHandover $newHandover): ShiftHandoverReversal
    {
        return DB::transaction(function () use ($reversal, $newHandover) {
            $reversal = ShiftHandoverReversal::whereKey($reversal->id)->lockForUpdate()->firstOrFail();
            if ($reversal->status !== 'confirmed') {
                throw new ConflictHttpException('REVERSAL_NOT_CONFIRMED');
            }
            $reversal->update(['corrected_handover_id' => $newHandover->id]);

            return $reversal;
        });
    }

    private function createReversalLedgerRow(
        string $partyType,
        string $partyId,
        string $reversalId,
        string $txType,
        string $amountSar,
        bool $isCashIn,
        string $counterpartName,
        ?string $shiftId,
        string $handoverId
    ): void {
        if ($partyType === 'cashier') {
            CashierCustodyTransaction::create([
                'cashier_id' => $partyId,
                'reversal_id' => $reversalId,
                'transaction_type' => $txType,
                'amount' => $amountSar,
                'is_cash_in' => $isCashIn,
                'counterpart_name' => $counterpartName,
                'related_shift_id' => $shiftId,
                'related_handover_id' => $handoverId,
                'transaction_date' => now(),
                'category' => 'handover_reversal',
            ]);
        } elseif ($partyType === 'branch_manager') {
            PersonalLedgerTransaction::create([
                'branch_manager_id' => $partyId,
                'reversal_id' => $reversalId,
                'transaction_type' => $txType,
                'amount' => $amountSar,
                'is_cash_in' => $isCashIn,
                'cashier_name' => $counterpartName,
                'related_shift_id' => $shiftId,
                'related_handover_id' => $handoverId,
                'transaction_date' => now(),
                'category' => 'handover_reversal',
            ]);
        }
    }

    /**
     * CASH-16 / BE-16: Cashier submits debt repayment handover to branch manager.
     * Debt liability is not reduced until the manager physically confirms the handover.
     *
     * @throws ValidationException|AccessDeniedHttpException
     */
    public function submitDebtRepayment(Cashier $cashier, string $managerId, int $amountHalalas, ?string $notes = null): ShiftDebtRepayment
    {
        if ($amountHalalas <= 0 || $amountHalalas > self::MAX_MONETARY_HALALAS) {
            throw ValidationException::withMessages(['amountHalalas' => 'Repayment amount in halalas must be positive.']);
        }

        return DB::transaction(function () use ($cashier, $managerId, $amountHalalas, $notes) {
            // Lock Cashier row to serialize concurrent repayment submissions for this cashier
            $cashier = Cashier::whereKey($cashier->id)->lockForUpdate()->firstOrFail();

            $manager = BranchManager::findOrFail($managerId);
            if ((string) $manager->branch_id !== (string) $cashier->branch_id) {
                throw new AccessDeniedHttpException('BRANCH_MANAGER_BRANCH_MISMATCH');
            }

            $branchId = (string) $cashier->branch_id;
            $companyId = DB::table('branches')->where('id', $branchId)->value('asab_company_id');

            // Re-read current debt liability of cashier under row lock
            $in = (float) CashierCustodyTransaction::where('cashier_id', $cashier->id)->where('is_cash_in', true)->sum('amount');
            $out = (float) CashierCustodyTransaction::where('cashier_id', $cashier->id)->where('is_cash_in', false)->sum('amount');
            $currentDebtSar = max(0.0, round($in - $out, 2));
            $currentDebtHalalas = (int) round($currentDebtSar * 100);

            if ($currentDebtHalalas <= 0) {
                throw ValidationException::withMessages(['amountHalalas' => 'Cashier has no outstanding debt liability to repay.']);
            }

            if ($amountHalalas > $currentDebtHalalas) {
                throw ValidationException::withMessages(['amountHalalas' => 'Repayment amount exceeds outstanding debt balance.']);
            }

            // Check if an identical pending repayment already exists (idempotency / prevent submission replay)
            // Note: Since Cashier row is exclusively locked, pending submissions for this cashier are serialized
            $existing = ShiftDebtRepayment::where('cashier_id', (string) $cashier->id)
                ->where('manager_id', (string) $manager->id)
                ->where('status', 'pending_manager_confirmation')
                ->where('amount_halalas', $amountHalalas)
                ->first();

            if ($existing) {
                return $existing;
            }

            // Prevent pending submissions from cumulatively exceeding outstanding debt
            $pendingAmountHalalas = (int) ShiftDebtRepayment::where('cashier_id', (string) $cashier->id)
                ->where('status', 'pending_manager_confirmation')
                ->sum('amount_halalas');

            if (($amountHalalas + $pendingAmountHalalas) > $currentDebtHalalas) {
                throw ValidationException::withMessages(['amountHalalas' => 'Total pending repayments exceed remaining outstanding debt balance.']);
            }

            return ShiftDebtRepayment::create([
                'company_id' => $companyId ?? $branchId,
                'branch_id' => $branchId,
                'cashier_id' => (string) $cashier->id,
                'manager_id' => (string) $manager->id,
                'amount_halalas' => $amountHalalas,
                'notes' => $notes,
                'status' => 'pending_manager_confirmation',
            ]);
        });
    }

    /**
     * CASH-16 / BE-16: Branch manager confirms debt repayment handover.
     * Reduces cashier debt statement balance without reopening reports or creating phantom sales.
     *
     * @return array{repayment: ShiftDebtRepayment, amount_halalas: int, remaining_debt_halalas: int}
     *
     * @throws ConflictHttpException|AccessDeniedHttpException
     */
    public function confirmDebtRepayment(ShiftDebtRepayment $repayment, Model $actor): array
    {
        return DB::transaction(function () use ($repayment, $actor) {
            // Canonical Lock Hierarchy: 1. Cashier -> 2. ShiftDebtRepayment
            // Prevents cross-flow deadlock when concurrent submitDebtRepayment and confirmDebtRepayment race
            $cashier = Cashier::whereKey($repayment->cashier_id)->lockForUpdate()->firstOrFail();
            $repayment = ShiftDebtRepayment::whereKey($repayment->id)->lockForUpdate()->firstOrFail();

            if ($repayment->status !== 'pending_manager_confirmation') {
                throw new ConflictHttpException('REPAYMENT_ALREADY_RESOLVED');
            }

            if (! ($actor instanceof BranchManager) || (string) $actor->id !== (string) $repayment->manager_id) {
                throw new AccessDeniedHttpException('ONLY_ASSIGNED_MANAGER_MAY_CONFIRM_REPAYMENT');
            }

            if ((string) $actor->branch_id !== (string) $repayment->branch_id) {
                throw new AccessDeniedHttpException('BRANCH_MANAGER_BRANCH_MISMATCH');
            }

            $manager = BranchManager::findOrFail($repayment->manager_id);

            // Re-verify current debt balance under lock to guarantee no overpayment
            $in = (float) CashierCustodyTransaction::where('cashier_id', $cashier->id)->where('is_cash_in', true)->sum('amount');
            $out = (float) CashierCustodyTransaction::where('cashier_id', $cashier->id)->where('is_cash_in', false)->sum('amount');
            $currentDebtSar = max(0.0, round($in - $out, 2));
            $currentDebtHalalas = (int) round($currentDebtSar * 100);

            if ($currentDebtHalalas <= 0 || $repayment->amount_halalas > $currentDebtHalalas) {
                throw new ConflictHttpException('REPAYMENT_EXCEEDS_CURRENT_DEBT');
            }

            $sarUnits = intdiv($repayment->amount_halalas, 100);
            $sarFraction = abs($repayment->amount_halalas % 100);
            $amountSar = sprintf('%d.%02d', $sarUnits, $sarFraction);

            // 1. Cashier custody row: Debt Repayment (Cash Out, reduces debt liability)
            CashierCustodyTransaction::create([
                'cashier_id' => $cashier->id,
                'debt_repayment_id' => $repayment->id,
                'transaction_type' => 'Debt Repayment',
                'amount' => $amountSar,
                'is_cash_in' => false,
                'counterpart_name' => $manager->name,
                'transaction_date' => now(),
                'category' => 'debt_repayment',
            ]);

            // 2. Manager personal ledger row: Debt Repayment (Cash In, receiving physical cash)
            PersonalLedgerTransaction::create([
                'branch_manager_id' => $manager->id,
                'debt_repayment_id' => $repayment->id,
                'transaction_type' => 'Debt Repayment',
                'amount' => $amountSar,
                'is_cash_in' => true,
                'cashier_name' => $cashier->name,
                'transaction_date' => now(),
                'category' => 'debt_repayment',
            ]);

            $repayment->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'confirmed_by_id' => (string) $actor->id,
            ]);

            $remainingDebtHalalas = max(0, $currentDebtHalalas - $repayment->amount_halalas);

            return [
                'repayment' => $repayment,
                'amount_halalas' => $repayment->amount_halalas,
                'remaining_debt_halalas' => $remainingDebtHalalas,
            ];
        });
    }
}
