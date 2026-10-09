<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;

/**
 * SRS ACC-6.4 / HEAD-2.5 — the shift close → approval chain.
 *
 * Close does NOT finalize a shift; it moves it to `pending_review` and mints a
 * `module_key='shifts'` pipeline operation (SHF-) so the shift walks the same
 * accountant→head chain as every other record. The expected cash is derived
 * server-side (opening float + sales − non-cash), never trusted from the body —
 * closing the forge where a branch could invent its own expected figure.
 *
 * On head final-approval a negative variance (cash shortage) is charged to the
 * cashier via the shared EmployeeAllocationService («خصم فرق كاش»), unless the
 * accountant recorded an explicit split first. On rejection the shift reopens.
 */
class ShiftCloseService
{
    public const CATEGORY = 'cash_variance';

    public const CATEGORY_LABEL_AR = 'خصم فرق كاش';

    public function __construct(
        private readonly OperationFactory $factory,
        private readonly EmployeeAllocationService $allocations,
        private readonly RealtimeBroadcaster $rt,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Close a shift into the review pipeline.
     *
     * @param  array{cashActualHalalas:int, cashCountState?:string, countEvidence?:array{expectedHalalas:int, varianceHalalas:int, pendingIncomingCountedHalalas:int}, cardTotalHalalas?:int, aggregatorTotalsHalalas?:int, aggregatorBreakdown?:array<int, array{aggregator:?string, amountHalalas:int}>, notes?:string}  $data
     * @return array{shift: Shift, operation: Operation}
     */
    public function close(Shift $shift, array $data, AsabUser $actor, string $origin = 'mobile'): array
    {
        $cashActual = (int) $data['cashActualHalalas'];
        $card = (int) ($data['cardTotalHalalas'] ?? 0);
        $aggregator = (int) ($data['aggregatorTotalsHalalas'] ?? 0);

        return DB::transaction(function () use ($shift, $actor, $origin, $cashActual, $card, $aggregator, $data) {
            $shift = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $this->assertClosable($shift);

            // Derive committed financial facts from the locked, current row.
            $expectedCash = (int) ($shift->opening_float ?? 0) + max(0, (int) $shift->sales_amount - $card - $aggregator);
            $variance = $cashActual - $expectedCash;
            // S1-10: a stored physical count carries its own server-calculated expected cash and variance
            // (confirmed opening only; pending incoming cash excluded). Never recomputed from sales here.
            $evidence = $data['countEvidence'] ?? null;
            if (is_array($evidence)) {
                $expectedCash = (int) $evidence['expectedHalalas'];
                $variance = (int) $evidence['varianceHalalas'];
            }
            $shift->update([
                'status' => 'pending_review',
                'ended_at' => now(),
                'cash_actual' => $cashActual,
                'cash_expected' => $expectedCash,
                'variance' => $variance,
                'notes' => $data['notes'] ?? null,
                'cash_count_state' => $data['cashCountState'] ?? null,
                'pending_incoming_counted' => is_array($evidence) ? (int) $evidence['pendingIncomingCountedHalalas'] : null,
            ]);

            $op = $this->factory->createFromUpload('shifts', [
                'shiftId' => $shift->id,
                'branchId' => $shift->branch_id,
                'cashierEmployeeId' => $shift->cashier_employee_id,
                'cashierName' => $shift->cashier_name,
                'shiftType' => $shift->shift_type,
                'openingFloatHalalas' => (int) ($shift->opening_float ?? 0),
                'salesHalalas' => (int) $shift->sales_amount,
                'cardTotalHalalas' => $card,
                'aggregatorTotalsHalalas' => $aggregator,
                'aggregatorBreakdown' => array_values($data['aggregatorBreakdown'] ?? []),
                'cashExpectedHalalas' => $expectedCash,
                'cashActualHalalas' => $cashActual,
                'varianceHalalas' => $variance,
                'legacyShiftId' => $shift->legacy_shift_id,
            ] + (isset($data['cashCountState']) ? ['cashCountState' => (string) $data['cashCountState']] : [])
              + (is_array($evidence) ? ['pendingIncomingCountedHalalas' => (int) $evidence['pendingIncomingCountedHalalas']] : []),
                $actor, $shift->branch_id, (int) $shift->sales_amount, $origin);

            return ['shift' => $shift->fresh(), 'operation' => $op];
        });
    }

    /**
     * Record the accountant's explicit split of the cash gap before head
     * approval. Amounts must sum to |variance|. Stored on the SHF operation.
     *
     * @param  array<int, array{employeeId?:string, empNumber?:string, amountHalalas:int}>  $allocations
     * @return array<int, array{employeeId:string, employeeName:string, amountHalalas:int}>
     */
    public function setVarianceAllocations(Operation $op, array $allocations, AsabUser $actor): array
    {
        return DB::transaction(function () use ($op, $allocations, $actor): array {
            $op = Operation::query()->whereKey($op->id)->lockForUpdate()->firstOrFail();
            if ($op->module_key !== 'shifts') {
                throw new AsabException('NOT_A_SHIFT_OPERATION', 'Variance allocations require a shift operation', 'تخصيص الفارق يتطلب عملية وردية', 409);
            }
            if (in_array($op->status, [Operation::STATUS_FINAL, Operation::STATUS_REJECTED], true)) {
                throw new AsabException('OP_ALREADY_FINAL', 'Operation is locked and can no longer be modified', 'لا يمكن تعديل عملية مُغلقة', 409);
            }

            $shiftStub = $this->shiftFor($op);
            if ($shiftStub === null) {
                throw new AsabException('SHIFT_NOT_FOUND', 'Shift operation has no current shift', 'الوردية المرتبطة بالعملية غير موجودة', 409);
            }
            $shift = Shift::query()->whereKey($shiftStub->id)->lockForUpdate()->firstOrFail();
            if ((int) $shift->variance < 0 && $shift->cash_count_state === 'counted') {
                throw new AsabException('BRANCH_ALLOCATION_AUTHORITATIVE', 'The branch liability allocation is the only authority for this shortage', 'توزيع الفرع هو المرجع الوحيد لعجز هذه الوردية', 409);
            }
            $target = abs((int) $shift->variance);

            $rows = [];
            $amounts = [];
            foreach ($allocations as $allocation) {
                $employee = $this->allocations->resolveEmployee($op, $allocation);
                $amount = (int) $allocation['amountHalalas'];
                $amounts[] = $amount;
                $rows[] = ['employeeId' => $employee->id, 'employeeName' => $employee->name, 'amountHalalas' => $amount];
            }
            $this->allocations->assertSum($amounts, $target);

            $payload = $op->payload ?? [];
            $payload['varianceAllocations'] = array_map(fn ($row) => ['employeeId' => $row['employeeId'], 'amountHalalas' => $row['amountHalalas']], $rows);
            $op->update(['payload' => $payload]);
            $op->steps()->create([
                'stage_id' => 'allocation',
                'action' => 'حدث المحاسب توزيع عجز الوردية',
                'actor_user_id' => $actor->id,
                'actor_label' => $actor->name,
                'note' => null,
                'meta' => ['varianceAllocations' => $payload['varianceAllocations']],
                'occurred_at' => now(),
            ]);

            return $rows;
        });
    }

    /**
     * On head final-approval: charge the cash shortage and close the shift.
     * Auto-allocates the full gap to the shift's cashier unless the accountant
     * recorded a split. Idempotent (reverse-then-insert). Surplus/zero → no post.
     */
    public function onFinalApproved(Operation $op, AsabUser $actor): void
    {
        DB::transaction(function () use ($op, $actor) {
            $lockedOperation = Operation::query()->whereKey($op->id)->lockForUpdate()->firstOrFail();
            if ($lockedOperation->status !== Operation::STATUS_FINAL || $lockedOperation->module_key !== 'shifts') {
                throw new AsabException('OP_NOT_FINAL', 'Shift operation is not final-approved', 'العملية ليست معتمدة نهائياً', 409);
            }
            $shift = $this->shiftFor($lockedOperation);
            if ($shift === null) {
                throw new AsabException('SHIFT_NOT_FOUND', 'Shift operation has no current shift', 'الوردية المرتبطة بالعملية غير موجودة', 409);
            }
            $shift = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($shift->status !== 'pending_review') {
                throw new AsabException('SHIFT_NOT_PENDING_REVIEW', 'Shift is no longer pending review', 'الوردية لم تعد بانتظار المراجعة', 409);
            }
            $variance = (int) $shift->variance;
            if ($variance < 0 && $shift->cash_count_state === 'counted') {
                // D15: a legacy shift with a real count is governed by the branch liability allocation
                // (S1-07). Admin must not charge the shortage on its own before S1-11 posts it once.
                throw new AsabException('BRANCH_LIABILITY_APPROVAL_PENDING', 'The shortage is governed by the branch liability allocation and its manager approval', 'عجز هذه الوردية يُحدَّد بتوزيع الفرع واعتماد مدير الفرع', 409);
            }
            if ($variance < 0) {
                $rows = $this->resolveAllocationRows($lockedOperation, $shift, abs($variance));
                if ($rows === []) {
                    throw new AsabException('SHIFT_ALLOCATION_REQUIRED', 'A shortage requires an employee allocation', 'يتطلب العجز تخصيصاً لموظف', 409);
                }
                $this->allocations->post($lockedOperation, self::CATEGORY, self::CATEGORY_LABEL_AR, $rows, $actor);
            }
            $shift->update(['status' => 'closed']);
            $shiftId = $shift->id;
            DB::afterCommit(function () use ($shiftId): void {
                try {
                    $this->rt->shiftChanged(Shift::query()->findOrFail($shiftId), 'closed');
                } catch (\Throwable $exception) {
                    Log::error('Final-approved shift projection failed after commit', [
                        'shift_id' => $shiftId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
        });
    }

    /** On rejection: reopen the shift and notify its branch manager. */
    public function onRejected(Operation $op, ?string $reason): void
    {
        $shift = $this->shiftFor($op);
        if ($shift === null) {
            return;
        }

        $shift->update(['status' => 'active', 'ended_at' => null]);
        $this->rt->shiftChanged($shift->fresh(), 'reopened');
        if ($shift->branch_id && $shift->company_id) {
            $this->notifications->pushToBranch(
                $shift->company_id, $shift->branch_id, 'branch', 'shift.reopened',
                'أُعيدت الوردية للمراجعة', $reason ? ('السبب: '.$reason) : null,
                null, ['type' => 'operation', 'id' => $op->id],
            );
        }
    }

    /**
     * @return array<int, array{employee:Employee, amountHalalas:int}>
     */
    private function resolveAllocationRows(Operation $op, Shift $shift, int $target): array
    {
        // Accountant's explicit split, if recorded and still summing to the gap.
        $stored = $op->payload['varianceAllocations'] ?? [];
        if ($stored !== [] && array_sum(array_map(fn ($a) => (int) $a['amountHalalas'], $stored)) === $target) {
            $rows = [];
            foreach ($stored as $a) {
                $rows[] = ['employee' => $this->allocations->resolveEmployee($op, $a), 'amountHalalas' => (int) $a['amountHalalas']];
            }

            return $rows;
        }

        // Default: the whole gap on the shift's cashier.
        if ($shift->cashier_employee_id === null) {
            return [];
        }
        $cashier = Employee::where('id', $shift->cashier_employee_id)->first();

        return $cashier ? [['employee' => $cashier, 'amountHalalas' => $target]] : [];
    }

    private function shiftFor(Operation $op): ?Shift
    {
        $shiftId = $op->payload['shiftId'] ?? null;

        return $shiftId ? Shift::where('id', $shiftId)->first() : null;
    }

    private function assertClosable(Shift $shift): void
    {
        if ($shift->isBranchManagerShift()) {
            throw new AsabException(
                'SHIFT_NOT_CLOSABLE',
                'A branch manager workday is closed from the mobile daily report, not the shift pipeline',
                'وردية مدير الفرع تُغلق من التقرير اليومي في التطبيق، وليس من هنا',
                422,
                ['role' => $shift->role],
            );
        }

        if (! in_array($shift->status, ['active', 'late'], true)) {
            throw new AsabException(
                'SHIFT_ALREADY_CLOSED',
                'Shift is no longer open',
                'تم إغلاق هذه الوردية مسبقاً',
                409,
                ['currentStatus' => $shift->status],
            );
        }
    }
}
