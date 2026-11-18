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

        if ($op->isPkg6DailyReport()) {
            throw new AsabException(
                'PKG6_DAY_REPORT_READ_ONLY',
                'PKG-6 daily sales report variance cannot be assigned via legacy route',
                'لا يمكن توزيع فرق تقرير مبيعات يوم PKG-6 عبر المسار القديم',
                409,
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
            // 1. Establish Operation row lock first to serialize concurrent allocation writes
            if ($op->exists) {
                Operation::whereKey($op->id)->lockForUpdate()->first();
            }

            // 2. Resolve all employees and lock them in deterministic ascending order to prevent deadlocks and serialize against settlement approvals
            $resolvedEmps = [];
            foreach ($allocations as $a) {
                $resolvedEmps[] = [
                    'employee' => $this->resolveEmployee($op, $a),
                    'amountHalalas' => (int) $a['amountHalalas'],
                ];
            }

            $empIds = collect($resolvedEmps)->pluck('employee.id')->filter()->unique()->sort()->values();
            if ($empIds->isNotEmpty()) {
                Employee::whereIn('id', $empIds)->orderBy('id')->lockForUpdate()->get();
            }

            $created = [];
            $stored = [];
            foreach ($resolvedEmps as $item) {
                $emp = $item['employee'];
                $amount = $item['amountHalalas'];
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
        // 1. If explicit UUID employeeId is provided and exists in branch, use it immediately
        if (! empty($a['employeeId'])) {
            $byUuid = Employee::where('company_id', $op->company_id)
                ->when($op->branch_id, fn ($q) => $q->where('branch_id', $op->branch_id))
                ->where('id', $a['employeeId'])
                ->first();
            if ($byUuid) {
                return $byUuid;
            }
        }

        // 2. Otherwise fall back to employee number / identifier
        $rawNeedle = $a['empNumber'] ?? ($a['employeeId'] ?? null);
        if ($rawNeedle === null || trim((string) $rawNeedle) === '') {
            throw new AsabException('VALIDATION_ERROR', 'Employee reference is required', 'معرف الموظف مطلوب', 422, [
                'employeeId' => ['Employee identifier is required'],
            ]);
        }

        $needle = trim((string) $rawNeedle);

        // Also check if $needle itself is an exact primary key id
        $byDirectId = Employee::where('company_id', $op->company_id)
            ->when($op->branch_id, fn ($q) => $q->where('branch_id', $op->branch_id))
            ->where('id', $needle)
            ->first();
        if ($byDirectId) {
            return $byDirectId;
        }

        $matches = Employee::where('company_id', $op->company_id)
            ->when($op->branch_id, fn ($q) => $q->where('branch_id', $op->branch_id))
            ->where(function ($q) use ($needle) {
                $q->where('emp_number', $needle);
                if (preg_match('/^(?:EMP-?)?(\d+)$/i', $needle, $m)) {
                    $digits = $m[1];
                    $padded = 'EMP-'.str_pad((string) (int) $digits, 4, '0', STR_PAD_LEFT);
                    $q->orWhere('emp_number', $padded)
                        ->orWhere('emp_number', (string) (int) $digits);
                }
            })
            ->get();

        if ($matches->isEmpty()) {
            throw new AsabException('VALIDATION_ERROR', 'Employee not found in branch', 'الموظف غير موجود في الفرع', 422, [
                'employeeId' => ['unknown employee '.$needle],
            ]);
        }

        if ($matches->count() > 1) {
            throw new AsabException(
                'AMBIGUOUS_EMPLOYEE_REF',
                'Multiple employees match this employee number in the branch. Please specify employee by ID.',
                'الرقم الوظيفي يحتمل أكثر من موظف في هذا الفرع. يرجى تحديد الموظف بدقة.',
                422,
                ['employeeId' => ['Ambiguous employee ref matches multiple branch employees: '.$needle]],
            );
        }

        return $matches->first();
    }
}
