<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Admin\Services\CommandIdempotencyContext;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\ShiftDebtRepayment;
use Modules\Shift\Models\ShiftHandoverReversal;
use Modules\Shift\Models\ShiftHandoverSettlement;
use Modules\Shift\Services\HandoverSettlementService;
use Modules\Shift\Transformers\ShiftHandoverSettlementResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public controller managing post-receipt additive settlements.
 */
class HandoverSettlementController extends Controller
{
    public function __construct(
        private HandoverSettlementService $settlementService
    ) {}

    /**
     * Settle a confirmed handover receipt with dual counterpart ledger movements.
     */
    public function settle(Request $request, string $receiptId): Response
    {
        try {
            $actor = auth()->user();
            if (! $actor) {
                return HandoverErrorResponse::domain('UNAUTHORIZED_SETTLEMENT_ACTOR', 403);
            }

            // F08: Public financial writer remains unavailable while settlement authority is unresolved
            app(\Modules\Shift\Policies\HandoverSettlementActorPolicy::class)->assertPublicWriterAuthorized();

            // Reject forbidden client override fields
            $forbiddenFields = [
                'signed_delta', 'signed_delta_halalas',
                'actor', 'actor_id', 'actor_type',
                'tenant', 'company_id', 'branch_id',
                'sender', 'sender_id', 'sender_type',
                'recipient', 'recipient_id', 'recipient_type',
            ];
            foreach ($forbiddenFields as $forbidden) {
                if ($request->has($forbidden)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Field '{$forbidden}' is server-derived and cannot be supplied by the client.",
                        'code' => 'FORBIDDEN_REQUEST_FIELD',
                    ], 422);
                }
            }

            // F13: Enforce shared DECIMAL(12,2) limit of 999999999999 halalas
            $validator = Validator::make($request->all(), [
                'settlement_type' => 'required|string|in:overpayment_return,underpayment_supplement',
                'amount_halalas' => 'required|integer|min:1|max:999999999999',
                'reason' => 'required|string|min:1|max:1000',
                'expected_settlement_version' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $receipt = CashierShiftHandoverReceipt::findOrFail($receiptId);

            $operationId = (string) ($request->header('Idempotency-Key')
                ?? $request->header('X-Operation-Id')
                ?? Str::uuid());

            $settlement = $this->settlementService->settle(
                $receipt,
                $actor,
                (string) $request->input('settlement_type'),
                (int) $request->input('amount_halalas'),
                (int) $request->input('expected_settlement_version'),
                (string) $request->input('reason'),
                $operationId
            );

            $response = response()->json([
                'success' => true,
                'message' => 'Settlement recorded successfully',
                'data' => new ShiftHandoverSettlementResource($settlement),
            ], 201);

            if (app()->bound(CommandIdempotencyContext::class)) {
                app(CommandIdempotencyContext::class)->completeWithinBusinessTransaction($response);
            }

            return $response;
        } catch (\Throwable $e) {
            return HandoverErrorResponse::from($e, 'settle');
        }
    }

    /**
     * Retrieve settlements and effective amounts for a confirmed receipt.
     */
    public function index(string $receiptId): JsonResponse
    {
        try {
            $actor = auth()->user();
            if (! $actor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required',
                    'code' => 'UNAUTHORIZED_ACTOR',
                ], 403);
            }

            $receipt = CashierShiftHandoverReceipt::findOrFail($receiptId);

            // F05: Authorize receipt read against receipt branch & company scope
            $provenance = $this->settlementService->resolveProvenance($receipt);
            app(\Modules\Shift\Policies\HandoverSettlementActorPolicy::class)
                ->assertCanReadReceipt($actor, $receipt, $provenance['branch_id']);

            $settlements = ShiftHandoverSettlement::where('original_receipt_id', $receipt->id)
                ->orderBy('created_at')
                ->get();

            $originalHalalas = \Modules\Shift\Services\ShiftCashAvailabilityService::minor((string) $receipt->confirmed_amount);
            $totalDeltaHalalas = 0;
            foreach ($settlements as $settlement) {
                $totalDeltaHalalas = \Modules\Shift\Services\ShiftCashAvailabilityService::add($totalDeltaHalalas, (int) $settlement->signed_delta_halalas);
            }
            $effectiveHalalas = \Modules\Shift\Services\ShiftCashAvailabilityService::add($originalHalalas, $totalDeltaHalalas);

            $effectiveUnits = intdiv($effectiveHalalas, 100);
            $effectiveCents = abs($effectiveHalalas % 100);
            $effectiveFormatted = sprintf('%d.%02d', $effectiveUnits, $effectiveCents);

            return response()->json([
                'success' => true,
                'data' => [
                    'original_receipt_id' => $receipt->id,
                    'original_amount' => (string) $receipt->confirmed_amount,
                    'original_amount_halalas' => $originalHalalas,
                    'settlement_version' => $settlements->count(),
                    'settlements' => ShiftHandoverSettlementResource::collection($settlements),
                    'total_delta_halalas' => $totalDeltaHalalas,
                    'effective_amount_halalas' => $effectiveHalalas,
                    'effective_amount' => $effectiveFormatted,
                ],
            ]);
        } catch (\Throwable $e) {
            return HandoverErrorResponse::from($e, 'index_settlements');
        }
    }

    /**
     * CASH-14 / BE-13: Recipient initiates two-party handover reversal dispute.
     */
    public function initiateReversalDispute(Request $request, string $handoverId): Response
    {
        try {
            $actor = auth()->user();
            if (! $actor) {
                return HandoverErrorResponse::domain('UNAUTHORIZED_ACTOR', 403);
            }

            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|min:1|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $handover = CashierShiftHandover::findOrFail($handoverId);

            $reversal = $this->settlementService->initiateReversal(
                $handover,
                $actor,
                (string) $request->input('reason')
            );

            return response()->json([
                'success' => true,
                'message' => 'Reversal dispute initiated successfully',
                'reversalId' => $reversal->id,
                'originalHandoverId' => $handover->id,
                'status' => $reversal->status,
                'data' => [
                    'reversalId' => $reversal->id,
                    'reversal_id' => $reversal->id,
                    'originalHandoverId' => $handover->id,
                    'original_handover_id' => $handover->id,
                    'status' => $reversal->status,
                    'reversal_reason' => $reversal->reversal_reason,
                    'initiated_by_user_id' => $reversal->initiated_by_user_id,
                    'created_at' => $reversal->created_at,
                ],
            ], 201);
        } catch (\Throwable $e) {
            return HandoverErrorResponse::from($e, 'initiateReversalDispute');
        }
    }

    /**
     * CASH-14 / BE-13: Original sender confirms two-party handover reversal.
     */
    public function confirmReversal(Request $request, string $reversalId): Response
    {
        try {
            $actor = auth()->user();
            if (! $actor) {
                return HandoverErrorResponse::domain('UNAUTHORIZED_ACTOR', 403);
            }

            $reversal = ShiftHandoverReversal::findOrFail($reversalId);

            $result = $this->settlementService->confirmReversal($reversal, $actor);
            $confirmedReversal = $result['reversal'];
            $amountHalalas = $result['reversed_amount_halalas'];

            return response()->json([
                'success' => true,
                'message' => 'Reversal confirmed successfully',
                'reversalId' => $confirmedReversal->id,
                'status' => 'confirmed',
                'reversedAmountHalalas' => $amountHalalas,
                'data' => [
                    'reversalId' => $confirmedReversal->id,
                    'reversal_id' => $confirmedReversal->id,
                    'status' => 'confirmed',
                    'reversedAmountHalalas' => $amountHalalas,
                    'confirmed_by_user_id' => $confirmedReversal->confirmed_by_user_id,
                    'confirmed_at' => $confirmedReversal->confirmed_at,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return HandoverErrorResponse::from($e, 'confirmReversal');
        }
    }

    /**
     * CASH-14 / BE-13: Original sender rejects two-party handover reversal.
     */
    public function rejectReversal(Request $request, string $reversalId): Response
    {
        try {
            $actor = auth()->user();
            if (! $actor) {
                return HandoverErrorResponse::domain('UNAUTHORIZED_ACTOR', 403);
            }

            $reversal = ShiftHandoverReversal::findOrFail($reversalId);

            $rejectedReversal = $this->settlementService->rejectReversal(
                $reversal,
                $actor,
                $request->input('rejection_reason')
            );

            return response()->json([
                'success' => true,
                'message' => 'Reversal rejected',
                'reversalId' => $rejectedReversal->id,
                'status' => 'rejected',
                'data' => [
                    'reversalId' => $rejectedReversal->id,
                    'reversal_id' => $rejectedReversal->id,
                    'status' => 'rejected',
                ],
            ], 200);
        } catch (\Throwable $e) {
            return HandoverErrorResponse::from($e, 'rejectReversal');
        }
    }

    /**
     * E06: Link subsequent corrected handover to confirmed reversal.
     */
    public function linkCorrectedHandover(Request $request, string $reversalId): Response
    {
        try {
            $actor = auth()->user();
            if (! $actor) {
                return HandoverErrorResponse::domain('UNAUTHORIZED_ACTOR', 403);
            }

            $validator = Validator::make($request->all(), [
                'corrected_handover_id' => 'required|uuid|exists:cashier_shift_handovers,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $reversal = ShiftHandoverReversal::findOrFail($reversalId);
            $newHandover = CashierShiftHandover::findOrFail($request->input('corrected_handover_id'));

            $updated = $this->settlementService->linkCorrectedHandover($reversal, $newHandover);

            return response()->json([
                'success' => true,
                'message' => 'Corrected handover linked successfully',
                'data' => [
                    'reversal_id' => $updated->id,
                    'corrected_handover_id' => $updated->corrected_handover_id,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return HandoverErrorResponse::from($e, 'linkCorrectedHandover');
        }
    }

    /**
     * CASH-16 / BE-16: Cashier submits physical cash debt repayment to manager.
     */
    public function submitRepayment(Request $request): Response
    {
        try {
            $actor = auth()->user();
            if (! ($actor instanceof Cashier)) {
                return HandoverErrorResponse::domain('ONLY_CASHIER_MAY_SUBMIT_REPAYMENT', 403);
            }

            $validator = Validator::make($request->all(), [
                'amountHalalas' => 'required|integer|min:1',
                'managerId' => 'required|uuid|exists:branch_managers,id',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $repayment = $this->settlementService->submitDebtRepayment(
                $actor,
                (string) $request->input('managerId'),
                (int) $request->input('amountHalalas'),
                $request->input('notes')
            );

            return response()->json([
                'success' => true,
                'message' => 'Debt repayment submitted successfully',
                'repaymentId' => $repayment->id,
                'status' => $repayment->status,
                'data' => [
                    'repaymentId' => $repayment->id,
                    'repayment_id' => $repayment->id,
                    'status' => $repayment->status,
                    'amountHalalas' => $repayment->amount_halalas,
                    'managerId' => $repayment->manager_id,
                    'cashierId' => $repayment->cashier_id,
                    'created_at' => $repayment->created_at,
                ],
            ], 201);
        } catch (\Throwable $e) {
            return HandoverErrorResponse::from($e, 'submitRepayment');
        }
    }

    /**
     * CASH-16 / BE-16: Branch manager confirms physical cash debt repayment.
     */
    public function confirmRepayment(Request $request, string $repayment): Response
    {
        try {
            $actor = auth()->user();
            if (! $actor) {
                return HandoverErrorResponse::domain('UNAUTHORIZED_ACTOR', 403);
            }

            $repaymentModel = ShiftDebtRepayment::findOrFail($repayment);

            $result = $this->settlementService->confirmDebtRepayment($repaymentModel, $actor);
            $confirmedRepayment = $result['repayment'];

            return response()->json([
                'success' => true,
                'message' => 'Debt repayment confirmed successfully',
                'repaymentId' => $confirmedRepayment->id,
                'status' => 'confirmed',
                'amountHalalas' => $result['amount_halalas'],
                'remainingDebtHalalas' => $result['remaining_debt_halalas'],
                'data' => [
                    'repaymentId' => $confirmedRepayment->id,
                    'repayment_id' => $confirmedRepayment->id,
                    'status' => 'confirmed',
                    'amountHalalas' => $result['amount_halalas'],
                    'remainingDebtHalalas' => $result['remaining_debt_halalas'],
                    'confirmed_by_id' => $confirmedRepayment->confirmed_by_id,
                    'confirmed_at' => $confirmedRepayment->confirmed_at,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return HandoverErrorResponse::from($e, 'confirmRepayment');
        }
    }
}
