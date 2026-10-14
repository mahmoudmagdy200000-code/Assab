<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportManagerReview;
use Modules\Shift\Services\AccountantReportCorrectionService;
use Modules\Shift\Services\ShiftReportCorrectionActorPolicy;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S1-11 Phase 5: Accountant report correction HTTP controller.
 * Enforces role & tenant authorization, optimistic concurrency, and atomic idempotency.
 */
class AccountantReportCorrectionController extends AsabController
{
    public function correct(
        Request $request,
        string $id,
        AccountantReportCorrectionService $correctionService,
        ShiftReportCorrectionActorPolicy $actorPolicy
    ): JsonResponse {
        return $this->run(function () use ($request, $id, $correctionService, $actorPolicy) {
            // Resolve cashier shift: support direct CashierShift UUID or mapped Admin Shift UUID
            $cashierShift = CashierShift::query()->whereKey($id)->first();
            if ($cashierShift === null) {
                $adminShift = AdminShift::query()->whereKey($id)->first();
                if ($adminShift !== null && $adminShift->legacy_shift_id !== null) {
                    $cashierShift = CashierShift::query()->whereKey($adminShift->legacy_shift_id)->first();
                }
            }

            if ($cashierShift === null) {
                throw new NotFoundHttpException('Shift not found.');
            }

            $actor = $request->user();
            if ($actor === null) {
                throw new AccessDeniedHttpException('Unauthenticated.');
            }

            // Authorize actor using zero-trust tenant policy
            $actorPolicy->authorize($actor, $cashierShift);

            // Check for forbidden gross sales tampering in payload
            $rawChanges = $request->input('changes', []);
            if (! is_array($rawChanges)) {
                throw ValidationException::withMessages(['changes' => 'Changes must be an array.']);
            }

            $forbiddenKeys = ['total_sales', 'gross_sales', 'sales', 'sales_amount'];
            foreach ($forbiddenKeys as $forbidden) {
                if (array_key_exists($forbidden, $rawChanges) || $request->has($forbidden)) {
                    throw new AsabException(
                        'GROSS_SALES_MODIFICATION_PROHIBITED',
                        'Modifying gross/total sales is prohibited for accountants.',
                        'تعديل إجمالي المبيعات محظور على المحاسبين.',
                        422
                    );
                }
            }

            $data = $request->validate([
                'expected_revision' => 'required|integer|min:1',
                'reason' => 'required|string|min:3',
                'changes' => 'sometimes|array',
                'changes.card_payments' => 'sometimes|string',
                'changes.cash_collected' => 'sometimes|string',
                'changes.counted_cash' => 'sometimes|required|'.\App\Support\ShiftMoneyValidation::SAR,
                'changes.handover_notes' => 'sometimes|nullable|string',
                'changes.aggregators' => 'sometimes|array',
                'shortage_allocations' => 'sometimes|nullable|array',
                'shortage_allocations.*.type' => 'sometimes|string|in:cashier,employee,branch_manager',
                'shortage_allocations.*.id' => 'sometimes|string',
                'shortage_allocations.*.amount' => 'sometimes|integer|min:1',
                'expected_allocation_version' => 'sometimes|nullable|integer|min:1',
            ]);

            // Derive operationId from registered idempotency command identity
            $operationId = (string) (
                $request->attributes->get('idempotency_key_id')
                ?? $request->header('Idempotency-Key')
                ?? Str::uuid()
            );

            $revision = $correctionService->correct(
                $cashierShift,
                $actor,
                changes: $data['changes'] ?? [],
                shares: $data['shortage_allocations'] ?? null,
                expectedRevision: (int) $data['expected_revision'],
                expectedAllocationVersion: $data['expected_allocation_version'] ?? null,
                reason: (string) $data['reason'],
                operationId: $operationId
            );

            $evidence = ShiftReportCashCount::query()->where('report_revision_id', $revision->id)->first();
            $allocation = ShiftLiabilityAllocation::query()
                ->where('cashier_shift_id', $cashierShift->id)
                ->whereNull('superseded_at')
                ->first();
            $managerReview = ShiftReportManagerReview::query()->where('report_revision_id', $revision->id)->first();

            $freshShift = $cashierShift->fresh();

            return $this->ok([
                'revision_number' => $revision->revision_number,
                'revision_id' => $revision->id,
                'evidence_id' => $evidence?->id,
                'allocation_version' => $allocation?->version,
                'manager_review_required' => $managerReview !== null && $managerReview->status === 'pending',
                'financial_results' => [
                    'total_sales' => (string) $freshShift->total_sales,
                    'card_payments' => (string) $freshShift->card_payments,
                    'cash_collected' => (string) $freshShift->cash_collected,
                    'variance' => (string) $freshShift->variance,
                    'variance_halalas' => $evidence?->variance_halalas,
                    'actual_count_halalas' => $evidence?->counted_halalas,
                    'expected_halalas' => $evidence?->expected_halalas,
                ],
            ]);
        });
    }

    /**
     * Override run to map HTTP conflict and access denied exceptions into standard Admin envelopes.
     */
    protected function run(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (AsabException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->messageAr, $e->details, $e->status);
        } catch (ConflictHttpException $e) {
            return $this->fail($e->getMessage(), $e->getMessage(), 'تعارض في بيانات الوردية', [], 409);
        } catch (AccessDeniedHttpException|AuthorizationException $e) {
            return $this->fail('FORBIDDEN', $e->getMessage() ?: 'Unauthorized', 'غير مصرح للوصول إلى هذه الوردية', [], 403);
        } catch (NotFoundHttpException|ModelNotFoundException $e) {
            return $this->fail('NOT_FOUND', $e->getMessage() ?: 'Resource not found', 'العنصر غير موجود', [], 404);
        } catch (ValidationException $e) {
            return $this->fail('VALIDATION_ERROR', 'Validation failed', 'فشل التحقق من البيانات', $e->errors(), 422);
        } catch (HttpExceptionInterface $e) {
            return $this->fail('HTTP_ERROR', $e->getMessage(), 'خطأ في الطلب', [], $e->getStatusCode());
        }
    }
}
