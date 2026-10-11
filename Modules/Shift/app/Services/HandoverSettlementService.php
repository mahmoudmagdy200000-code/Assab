<?php

namespace Modules\Shift\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\CashierShiftHistory;
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
}
