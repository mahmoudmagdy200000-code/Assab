<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;

/**
 * SRS ACC-1.4 — «الفرق يُخصم من حساب المسؤول».
 *
 * A sales operation's collection gap is charged to the employees responsible.
 * Each allocation posts a debit on the employee statement inside one
 * transaction; the operation payload records the allocation set for audit.
 *
 * A shortfall is stored as a NEGATIVE variance (`collected − expected`), so the
 * full-allocation rule compares against its magnitude — the pre-T04 code tested
 * `variance > 0` and therefore never fired on the very case the SRS describes.
 */
class SalesVarianceService
{
    public const CATEGORY = 'sales_variance';

    public const CATEGORY_LABEL_AR = 'فرق مبيعات';

    /**
     * @param  array<int, array{employeeId?:string, empNumber?:string, amountHalalas:int}>  $allocations
     * @return array<string, mixed>
     */
    public function assign(Operation $op, array $allocations, ?string $notes, AsabUser $actor): array
    {
        // NFR-10: a closed record never gains new financial movements.
        if (in_array($op->status, [Operation::STATUS_FINAL, Operation::STATUS_REJECTED], true)) {
            throw new AsabException(
                'OP_ALREADY_FINAL',
                'Operation is locked and cannot receive allocations',
                'لا يمكن تحميل الفروق على عملية مُغلقة',
                409,
                ['currentStatus' => $op->status],
            );
        }

        $sum = array_sum(array_map(fn ($a) => (int) $a['amountHalalas'], $allocations));
        $variance = $this->variance($op);
        $target = abs($variance);

        // «يجب أن يساوي مجموع التخصيصات قيمة الفارق» — partial or over-allocation
        // both leave the employee ledger out of step with the operation.
        if ($variance !== 0 && $sum !== $target) {
            throw new AsabException(
                'VALIDATION_ERROR',
                'Allocations must sum to the operation variance',
                'يجب أن يساوي مجموع التخصيصات قيمة الفارق',
                422,
                ['allocations' => ["expected total {$target} halalas, got {$sum}"]],
            );
        }

        $varianceTotal = $variance !== 0 ? $target : $sum;

        $rows = DB::transaction(function () use ($op, $allocations, $notes, $actor) {
            $created = [];
            $stored = [];
            foreach ($allocations as $a) {
                $emp = $this->resolveEmployee($op, $a);
                $amount = (int) $a['amountHalalas'];
                $movement = EmployeeMovement::create([
                    'employee_id' => $emp->id,
                    'movement_date' => now(),
                    'description' => self::CATEGORY_LABEL_AR.' — '.$op->public_id.($notes ? ' — '.$notes : ''),
                    'movement_type' => 'debit',
                    'category' => self::CATEGORY,
                    'amount' => $amount,
                    'ref_operation_id' => $op->id,
                    'created_by_id' => $actor->id,
                ]);
                $created[] = [
                    'id' => $movement->id,
                    'employeeId' => $emp->id,
                    'employeeName' => $emp->name,
                    'amountHalalas' => $amount,
                    'category' => self::CATEGORY,
                    'categoryLabelAr' => self::CATEGORY_LABEL_AR,
                    'appliedAt' => optional($movement->created_at)->toIso8601String(),
                ];
                $stored[] = ['employeeId' => $emp->id, 'amountHalalas' => $amount];
            }

            $payload = $op->payload ?? [];
            $payload['varianceAllocations'] = $stored;
            $payload['varianceNotes'] = $notes;
            $op->update(['payload' => $payload]);

            return $created;
        });

        return [
            'operationId' => $op->id,
            'varianceTotalHalalas' => $varianceTotal,
            'allocations' => $rows,
            'remainingUnallocatedHalalas' => max(0, $varianceTotal - $sum),
        ];
    }

    /**
     * The gap to charge. Reconciliation writes it under `payload.reconciliation`;
     * the root copy and the legacy `cashVarianceHalalas` key are read as fallbacks.
     */
    private function variance(Operation $op): int
    {
        $payload = $op->payload ?? [];

        return (int) ($payload['reconciliation']['varianceHalalas']
            ?? ($payload['varianceHalalas']
                ?? ($payload['cashVarianceHalalas'] ?? 0)));
    }

    /** Resolve an employee by id or employee-number within the operation's tenant + branch. */
    private function resolveEmployee(Operation $op, array $a): Employee
    {
        $ref = $a['employeeId'] ?? ($a['empNumber'] ?? null);
        $emp = Employee::where('company_id', $op->company_id)
            ->when($op->branch_id, fn ($q) => $q->where('branch_id', $op->branch_id))
            ->where(fn ($q) => $q->where('id', $ref)->orWhere('emp_number', $ref))
            ->first();

        if (! $emp) {
            throw new AsabException('VALIDATION_ERROR', 'Employee not found in branch', 'الموظف غير موجود في الفرع', 422, [
                'employeeId' => ['unknown employee '.$ref],
            ]);
        }

        return $emp;
    }
}
