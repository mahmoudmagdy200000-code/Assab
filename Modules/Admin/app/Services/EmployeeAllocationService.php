<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;

/**
 * The one place employee-responsibility charges are validated and posted to the
 * Employee Account ledger (ACC-1.4 sales shortfall, ACC-5.3 waste, §9.2 daily
 * inventory variance).
 *
 * Extracted so waste and inventory reach parity with the sales-variance rules
 * that already work: each employee is resolved inside the operation's tenant +
 * branch, and a category's debits are posted **idempotently** — re-posting the
 * same category for the same operation reverses the previous set first, so a
 * retried request can never double-charge an employee.
 */
class EmployeeAllocationService
{
    /**
     * Resolve an employee by id or employee-number inside the operation's
     * tenant + branch. 422 (not 404) — an unknown ref is bad allocation input.
     *
     * @param  array{employeeId?:string, empNumber?:string}  $ref
     */
    public function resolveEmployee(Operation $op, array $ref): Employee
    {
        $needle = $ref['employeeId'] ?? ($ref['empNumber'] ?? null);
        $emp = Employee::where('company_id', $op->company_id)
            ->when($op->branch_id, fn ($q) => $q->where('branch_id', $op->branch_id))
            ->where(fn ($q) => $q->where('id', $needle)->orWhere('emp_number', $needle))
            ->first();

        if (! $emp) {
            throw new AsabException('VALIDATION_ERROR', 'Employee not found in branch', 'الموظف غير موجود في الفرع', 422, [
                'employeeId' => ['unknown employee '.$needle],
            ]);
        }

        return $emp;
    }

    /**
     * Assert allocation amounts sum to a required target (in halalas). A partial
     * or over-allocation leaves the ledger out of step with the operation.
     *
     * @param  int[]  $amounts
     */
    public function assertSum(array $amounts, int $target): void
    {
        $sum = array_sum($amounts);
        if ($sum !== $target) {
            throw new AsabException(
                'VALIDATION_ERROR',
                'Allocations must sum to the charged value',
                'يجب أن يساوي مجموع التخصيصات قيمة الفارق',
                422,
                ['allocations' => ["expected total {$target} halalas, got {$sum}"]],
            );
        }
    }

    /**
     * Reverse any existing debits of `$category` posted for `$op` — the
     * idempotency primitive. Deletes rather than posting compensating credits:
     * these are correction retries, not accounting reversals.
     */
    public function reverse(Operation $op, string $category): int
    {
        return EmployeeMovement::where('ref_operation_id', $op->id)
            ->where('category', $category)
            ->delete();
    }

    public function hasPosted(Operation $op, string $category): bool
    {
        return EmployeeMovement::where('ref_operation_id', $op->id)->where('category', $category)->exists();
    }

    /**
     * Post one debit set for an operation under a category, reversing any prior
     * set for that (operation, category) first. Caller owns the surrounding
     * transaction when this is one step of a larger write.
     *
     * @param  array<int, array{employee:Employee, amountHalalas:int, label?:string}>  $rows
     * @return array<int, array<string, mixed>> the created movements
     */
    public function post(Operation $op, string $category, string $categoryLabelAr, array $rows, AsabUser $actor, string|\DateTimeInterface|null $date = null): array
    {
        return DB::transaction(function () use ($op, $category, $categoryLabelAr, $rows, $actor, $date) {
            $this->reverse($op, $category);

            $created = [];
            foreach ($rows as $row) {
                $emp = $row['employee'];
                $amount = (int) $row['amountHalalas'];
                $movement = EmployeeMovement::create([
                    'employee_id' => $emp->id,
                    'movement_date' => $date ?? now(),
                    'description' => $row['label'] ?? ($categoryLabelAr.' — '.$op->public_id),
                    'movement_type' => 'debit',
                    'category' => $category,
                    'amount' => $amount,
                    'ref_operation_id' => $op->id,
                    'created_by_id' => $actor->id,
                ]);
                $created[] = [
                    'id' => $movement->id,
                    'employeeId' => $emp->id,
                    'employeeName' => $emp->name,
                    'amountHalalas' => $amount,
                    'category' => $category,
                    'categoryLabelAr' => $categoryLabelAr,
                    'appliedAt' => optional($movement->created_at)->toIso8601String(),
                ];
            }

            return $created;
        });
    }
}
