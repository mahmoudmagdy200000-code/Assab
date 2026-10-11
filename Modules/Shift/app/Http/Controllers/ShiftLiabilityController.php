<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Services\CommandIdempotencyContext;
use Modules\Shift\Liability\ShiftLiabilityService;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShortagePostingService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * HTTP controller for normal counted-shortage liability allocations, confirmations,
 * employee responses, and branch manager approvals with atomic shortage posting reconciliation.
 */
class ShiftLiabilityController extends Controller
{
    public function __construct(
        private ShiftLiabilityService $liabilityService,
        private ShortagePostingService $shortagePostingService,
        private ShiftReportRevisionService $revisions,
    ) {}

    /**
     * Allocate liability shares for a shift shortage.
     */
    public function allocate(Request $request, string $shift): JsonResponse
    {
        try {
            $actor = auth()->user();
            if (! $actor) {
                return response()->json([
                    'success' => false,
                    'code' => 'UNAUTHORIZED_ACTOR',
                    'message' => 'Unauthorized actor',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'shares' => 'required|array|min:1',
                'shares.*.type' => 'required|string|in:cashier,branch_manager,employee',
                'shares.*.id' => 'required',
                'shares.*.amount' => 'required|integer|min:1',
                'expected_version' => 'required|integer|min:0',
                'expected_revision' => 'required|integer|min:1',
                'cashier_confirmed' => 'nullable|boolean',
                'reason' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $cashierShift = CashierShift::findOrFail($shift);
            $this->assertCurrentRevision($cashierShift, (int) $request->input('expected_revision'));
            $expectedVersion = (int) $request->input('expected_version', 0);
            $cashierConfirmed = (bool) $request->input('cashier_confirmed', false);
            $reason = $request->input('reason');
            $shares = $request->input('shares');

            $allocation = $this->liabilityService->allocate(
                $cashierShift->id,
                $actor,
                $shares,
                $expectedVersion,
                $cashierConfirmed,
                $reason
            );

            $response = response()->json([
                'success' => true,
                'message' => 'Liability allocated successfully',
                'data' => [
                    'id' => $allocation->id,
                    'cashier_shift_id' => $allocation->cashier_shift_id,
                    'version' => $allocation->version,
                    'report_revision' => $allocation->report_revision,
                    'variance_halalas' => $allocation->variance_halalas,
                    'created_by_type' => $allocation->created_by_type,
                    'created_by_id' => $allocation->created_by_id,
                    'reason' => $allocation->reason,
                    'cashier_confirmed_at' => $allocation->cashier_confirmed_at?->toIso8601String(),
                    'manager_approval_status' => $allocation->manager_approval_status,
                    'shares' => $allocation->shares->map(fn ($s) => [
                        'id' => $s->id,
                        'responsible_type' => $s->responsible_type,
                        'responsible_id' => $s->responsible_id,
                        'amount_halalas' => $s->amount_halalas,
                        'employee_response_status' => $s->employee_response_status,
                    ]),
                ],
            ], 201);

            if (app()->bound(CommandIdempotencyContext::class)) {
                app(CommandIdempotencyContext::class)->completeWithinBusinessTransaction($response);
            }

            return $response;
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (AccessDeniedHttpException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage() ?: 'FORBIDDEN',
                'message' => $e->getMessage(),
            ], 403);
        } catch (ConflictHttpException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    /**
     * Cashier confirms allocated liability.
     */
    public function confirm(Request $request, string $shift): JsonResponse
    {
        try {
            $actor = auth()->user();
            if (! $actor) {
                return response()->json([
                    'success' => false,
                    'code' => 'UNAUTHORIZED_ACTOR',
                    'message' => 'Unauthorized actor',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'version' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $cashierShift = CashierShift::findOrFail($shift);
            $version = (int) $request->input('version');

            $this->liabilityService->confirm($cashierShift->id, $actor, $version);

            $response = response()->json([
                'success' => true,
                'message' => 'Liability allocation confirmed successfully',
                'data' => [
                    'cashier_shift_id' => $cashierShift->id,
                    'version' => $version,
                ],
            ], 200);

            if (app()->bound(CommandIdempotencyContext::class)) {
                app(CommandIdempotencyContext::class)->completeWithinBusinessTransaction($response);
            }

            return $response;
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (AccessDeniedHttpException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage() ?: 'FORBIDDEN',
                'message' => $e->getMessage(),
            ], 403);
        } catch (ConflictHttpException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    /**
     * Responsible employee accepts or objects to assigned liability share.
     */
    public function respond(Request $request, string $shift): JsonResponse
    {
        try {
            $actor = auth()->user();
            if ($actor instanceof \Modules\Admin\Models\AsabUser) {
                if ($actor->status !== 'active') {
                    throw new AccessDeniedHttpException('ONLY_ASSIGNED_EMPLOYEE');
                }
                $linked = \Modules\Admin\Models\Employee::query()
                    ->where('asab_user_id', $actor->id)->where('company_id', $actor->company_id)
                    ->where('status', 'active')->limit(2)->get();
                if ($linked->count() !== 1) {
                    throw new AccessDeniedHttpException('ONLY_ASSIGNED_EMPLOYEE');
                }
                $actor = $linked->sole();
            }
            if (! $actor) {
                return response()->json([
                    'success' => false,
                    'code' => 'UNAUTHORIZED_ACTOR',
                    'message' => 'Unauthorized actor',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'version' => 'required|integer|min:1',
                'response' => 'required|string|in:accepted,objected',
                'reason' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $cashierShift = CashierShift::findOrFail($shift);
            $version = (int) $request->input('version');
            $employeeResponse = (string) $request->input('response');
            $reason = $request->input('reason');

            $this->liabilityService->respond($cashierShift->id, $actor, $version, $employeeResponse, $reason);

            $response = response()->json([
                'success' => true,
                'message' => 'Employee response recorded successfully',
                'data' => [
                    'cashier_shift_id' => $cashierShift->id,
                    'version' => $version,
                    'response' => $employeeResponse,
                ],
            ], 200);

            if (app()->bound(CommandIdempotencyContext::class)) {
                app(CommandIdempotencyContext::class)->completeWithinBusinessTransaction($response);
            }

            return $response;
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (AccessDeniedHttpException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage() ?: 'FORBIDDEN',
                'message' => $e->getMessage(),
            ], 403);
        } catch (ConflictHttpException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    /**
     * Branch manager approves allocation and executes atomic shortage reconciliation.
     */
    public function approve(Request $request, string $shift): JsonResponse
    {
        try {
            $actor = auth()->user();
            if (! $actor) {
                return response()->json([
                    'success' => false,
                    'code' => 'UNAUTHORIZED_ACTOR',
                    'message' => 'Unauthorized actor',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'version' => 'required|integer|min:1',
                'expected_revision' => 'required|integer|min:1',
                'explicit_self_share' => 'nullable|boolean',
                'operation_id' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $cashierShift = CashierShift::findOrFail($shift);
            $version = (int) $request->input('version');
            $explicitSelfShare = (bool) $request->input('explicit_self_share', false);

            $response = DB::transaction(function () use ($cashierShift, $actor, $version, $explicitSelfShare, $request) {
                $expectedRevision = (int) $request->input('expected_revision');
                $this->assertCurrentRevision($cashierShift, $expectedRevision);
                // 1. Manager approval via ShiftLiabilityService
                $this->liabilityService->approve($cashierShift->id, $actor, $version, $explicitSelfShare);

                // 2. Resolve expected report revision
                // The caller's explicit current revision is never synthesized.

                // 3. Resolve operation ID
                $operationId = (string) ($request->input('operation_id')
                    ?? $request->header('Idempotency-Key')
                    ?? Str::uuid());

                // 4. Reconcile liability posting atomically
                $postingResult = $this->shortagePostingService->reconcile(
                    $cashierShift,
                    $actor,
                    $expectedRevision,
                    $version,
                    $operationId
                );

                $res = response()->json([
                    'success' => true,
                    'message' => 'Liability approved and shortage reconciled successfully',
                    'data' => [
                        'cashier_shift_id' => $cashierShift->id,
                        'version' => $version,
                        'manager_approval_status' => 'approved',
                        'posting_result' => $postingResult,
                    ],
                ], 200);

                if (app()->bound(CommandIdempotencyContext::class)) {
                    app(CommandIdempotencyContext::class)->completeWithinBusinessTransaction($res);
                }

                return $res;
            });

            return $response;
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (AccessDeniedHttpException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage() ?: 'FORBIDDEN',
                'message' => $e->getMessage(),
            ], 403);
        } catch (ConflictHttpException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    private function assertCurrentRevision(CashierShift $source, int $expectedRevision): void
    {
        $shift = CashierShift::withoutEagerLoads()->whereKey($source->id)->lockForUpdate()->firstOrFail();
        $current = $this->revisions->currentCashierRevision($shift);
        if ($current === null || $current->revision_number !== $expectedRevision) {
            throw new ConflictHttpException('STALE_REPORT_REVISION');
        }
    }
}
