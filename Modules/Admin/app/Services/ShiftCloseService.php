<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
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
     * @param  array{cashActualHalalas:int, cardTotalHalalas?:int, aggregatorTotalsHalalas?:int, aggregatorBreakdown?:array<int, array{aggregator:?string, amountHalalas:int}>, notes?:string}  $data
     * @return array{shift: Shift, operation: Operation}
     */
    public function close(Shift $shift, array $data, AsabUser $actor, string $origin = 'mobile'): array
    {
        // A manager's mirrored workday is display-only: its money already reaches
        // the accountant as the branch's daily sales statement, so closing it
        // here would review the same riyals twice and charge a phantom cash gap.
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

        $cashActual = (int) $data['cashActualHalalas'];
        $card = (int) ($data['cardTotalHalalas'] ?? 0);
        $aggregator = (int) ($data['aggregatorTotalsHalalas'] ?? 0);
        // Server-derived expected cash: the float plus the cash portion of sales
        // (total sales minus what was taken by card / delivery apps).
        $expectedCash = (int) ($shift->opening_float ?? 0) + max(0, (int) $shift->sales_amount - $card - $aggregator);
        $variance = $cashActual - $expectedCash;

        return DB::transaction(function () use ($shift, $actor, $origin, $cashActual, $card, $aggregator, $expectedCash, $variance, $data) {
            $shift->update([
                'status' => 'pending_review',
                'ended_at' => now(),
                'cash_actual' => $cashActual,
                'cash_expected' => $expectedCash,
                'variance' => $variance,
                'notes' => $data['notes'] ?? null,
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
            ], $actor, $shift->branch_id, (int) $shift->sales_amount, $origin);

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
        $shift = $this->shiftFor($op);
        $target = abs((int) $shift->variance);

        $rows = [];
        $amounts = [];
        foreach ($allocations as $a) {
            $emp = $this->allocations->resolveEmployee($op, $a);
            $amount = (int) $a['amountHalalas'];
            $amounts[] = $amount;
            $rows[] = ['employeeId' => $emp->id, 'employeeName' => $emp->name, 'amountHalalas' => $amount];
        }
        $this->allocations->assertSum($amounts, $target);

        $payload = $op->payload ?? [];
        $payload['varianceAllocations'] = array_map(fn ($r) => ['employeeId' => $r['employeeId'], 'amountHalalas' => $r['amountHalalas']], $rows);
        $op->update(['payload' => $payload]);

        return $rows;
    }

    /**
     * On head final-approval: charge the cash shortage and close the shift.
     * Auto-allocates the full gap to the shift's cashier unless the accountant
     * recorded a split. Idempotent (reverse-then-insert). Surplus/zero → no post.
     */
    public function onFinalApproved(Operation $op, AsabUser $actor): void
    {
        $shift = $this->shiftFor($op);
        if ($shift === null) {
            return;
        }

        DB::transaction(function () use ($op, $actor, $shift) {
            $variance = (int) $shift->variance;
            if ($variance < 0) {
                $rows = $this->resolveAllocationRows($op, $shift, abs($variance));
                if ($rows !== []) {
                    $this->allocations->post($op, self::CATEGORY, self::CATEGORY_LABEL_AR, $rows, $actor);
                }
            }
            $shift->update(['status' => 'closed']);
        });

        $this->rt->shiftChanged($shift->fresh(), 'closed');
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
}
