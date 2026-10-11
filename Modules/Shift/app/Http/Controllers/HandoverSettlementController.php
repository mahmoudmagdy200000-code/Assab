<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Admin\Services\CommandIdempotencyContext;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
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
}
