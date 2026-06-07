<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;

/**
 * Allocate a sales operation's cash variance across employees (MISSING_Dashboard
 * §10). Each allocation is posted as a debit on the employee statement
 * (employee_movements) inside one transaction; the operation payload records the
 * allocation set for audit/replay.
 */
class SalesVarianceService
{
    /**
     * @param  array<int, array{employeeId?:string, empNumber?:string, amountHalalas:int}>  $allocations
     */
    public function assign(Operation $op, array $allocations, ?string $notes, AsabUser $actor): array
    {
        $sum = array_sum(array_map(fn ($a) => (int) $a['amountHalalas'], $allocations));
        $variance = (int) ($op->payload['varianceHalalas'] ?? ($op->payload['cashVarianceHalalas'] ?? 0));

        // Spec §10: when the operation carries a known variance, allocations must match it exactly.
        if ($variance > 0 && $sum !== $variance) {
            throw new AsabException(
                'VALIDATION_ERROR',
                'Allocations must sum to the operation variance',
                'يجب أن يساوي مجموع التخصيصات قيمة الفارق',
                422,
                ['allocations' => ["expected total {$variance} halalas, got {$sum}"]],
            );
        }
        $varianceTotal = $variance > 0 ? $variance : $sum;

        $rows = DB::transaction(function () use ($op, $allocations, $notes, $actor) {
            $created = [];
            $stored = [];
            foreach ($allocations as $a) {
                $emp = $this->resolveEmployee($op, $a);
                $amount = (int) $a['amountHalalas'];
                $movement = EmployeeMovement::create([
                    'employee_id' => $emp->id,
                    'movement_date' => now(),
                    'description' => 'تحميل فرق كاش — '.$op->public_id.($notes ? ' — '.$notes : ''),
                    'movement_type' => 'debit',
                    'amount' => $amount,
                    'ref_operation_id' => $op->id,
                    'created_by_id' => $actor->id,
                ]);
                $created[] = [
                    'id' => $movement->id,
                    'employeeId' => $emp->id,
                    'employeeName' => $emp->name,
                    'amountHalalas' => $amount,
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
