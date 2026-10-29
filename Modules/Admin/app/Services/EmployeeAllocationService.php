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
     * idempotency primitive (AUD-01).
     *
     * Posts compensating credit entries opposite each debit movement to maintain
     * a complete audit trail without deleting records.
     */
    public function reverse(Operation $op, string $category): int
    {
        return DB::transaction(function () use ($op, $category) {
            // 1. Lock the parent operation first to serialize allocation/reversal operations and prevent stale snapshot
            if ($op->exists) {
                Operation::whereKey($op->id)->lockForUpdate()->first();
            }

            $existingDebits = EmployeeMovement::where('ref_operation_id', $op->id)
                ->where('category', $category)
                ->where('movement_type', 'debit')
                ->lockForUpdate()
                ->get();

            if ($existingDebits->isEmpty()) {
                return 0;
            }

            // Lock all affected employees in deterministic ascending order to prevent deadlocks
            $empIds = $existingDebits->pluck('employee_id')->filter()->unique()->sort()->values();
            if ($empIds->isNotEmpty()) {
                Employee::whereIn('id', $empIds)->orderBy('id')->lockForUpdate()->get();
            }

            $existingCredits = EmployeeMovement::where('ref_operation_id', $op->id)
                ->where('category', $category)
                ->where('movement_type', 'credit')
                ->lockForUpdate()
                ->get();

            $reversedRefs = $existingCredits->pluck('ref')->filter()->all();

            $count = 0;
            foreach ($existingDebits as $debit) {
                $reversalRef = 'REV-'.substr(str_replace('-', '', (string) $debit->id), 0, 28);
                if (in_array($reversalRef, $reversedRefs, true)) {
                    continue;
                }

                EmployeeMovement::create([
                    'employee_id' => $debit->employee_id,
                    'movement_date' => now(),
                    'description' => 'عكس — '.$debit->description,
                    'movement_type' => 'credit',
                    'category' => $category,
                    'amount' => $debit->amount,
                    'ref_operation_id' => $op->id,
                    'created_by_id' => auth()->id() ?? $debit->created_by_id,
                    'ref' => $reversalRef,
                ]);

                $reversedRefs[] = $reversalRef;
                $count++;
            }

            return $count;
        });
    }

    public function hasPosted(Operation $op, string $category): bool
    {
        $debits = (int) EmployeeMovement::where('ref_operation_id', $op->id)
            ->where('category', $category)
            ->where('movement_type', 'debit')
            ->sum('amount');

        $credits = (int) EmployeeMovement::where('ref_operation_id', $op->id)
            ->where('category', $category)
            ->where('movement_type', 'credit')
            ->sum('amount');

        return ($debits - $credits) > 0;
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
            // 1. Establish Operation row lock first as the very first query to serialize concurrent allocation/reversal operations for this operation
            if ($op->exists) {
                Operation::whereKey($op->id)->lockForUpdate()->first();
            }

            // 2. Perform locking read for existing debits (forces Current Read under REPEATABLE READ, preventing stale snapshot)
            $existingDebits = EmployeeMovement::where('ref_operation_id', $op->id)
                ->where('category', $category)
                ->where('movement_type', 'debit')
                ->lockForUpdate()
                ->get();

            // 3. Lock all affected employees (both prior debits and new allocation targets)
            // in deterministic ascending order to prevent deadlocks across concurrent writes.
            $existingEmpIds = $existingDebits->pluck('employee_id');
            $newEmpIds = collect($rows)->map(fn ($r) => $r['employee']->id ?? ($r['employeeId'] ?? null));

            $allEmpIds = $existingEmpIds->concat($newEmpIds)->filter()->unique()->sort()->values();
            if ($allEmpIds->isNotEmpty()) {
                Employee::whereIn('id', $allEmpIds)->orderBy('id')->lockForUpdate()->get();
            }

            // E02c: Once-only idempotency guard: check if active debits match requested rows identically
            $existingCredits = EmployeeMovement::where('ref_operation_id', $op->id)
                ->where('category', $category)
                ->where('movement_type', 'credit')
                ->lockForUpdate()
                ->get();

            $reversedDebitIds = [];
            foreach ($existingCredits as $credit) {
                if (str_starts_with((string) $credit->ref, 'REV-')) {
                    $reversedPrefix = substr((string) $credit->ref, 4);
                    foreach ($existingDebits as $d) {
                        $dPrefix = substr(str_replace('-', '', (string) $d->id), 0, 28);
                        if ($dPrefix === $reversedPrefix) {
                            $reversedDebitIds[] = $d->id;
                        }
                    }
                }
            }

            $activeDebits = $existingDebits->whereNotIn('id', $reversedDebitIds)->values();

            if ($activeDebits->isNotEmpty() && $activeDebits->count() === count($rows)) {
                $requestedMap = [];
                foreach ($rows as $row) {
                    $empId = (string) ($row['employee']->id ?? ($row['employeeId'] ?? ''));
                    $requestedMap[$empId] = (int) $row['amountHalalas'];
                }

                $activeMap = [];
                foreach ($activeDebits as $d) {
                    $activeMap[(string) $d->employee_id] = (int) $d->amount;
                }

                ksort($requestedMap);
                ksort($activeMap);

                if ($requestedMap === $activeMap) {
                    // Identical retry: return existing movements without creating duplicate reverse/recharge movements
                    $existingResults = [];
                    foreach ($activeDebits as $movement) {
                        $emp = $movement->employee ?? Employee::find($movement->employee_id);
                        $existingResults[] = [
                            'id' => $movement->id,
                            'employeeId' => $movement->employee_id,
                            'employeeName' => $emp?->name ?? '',
                            'amountHalalas' => (int) $movement->amount,
                            'category' => $category,
                            'categoryLabelAr' => $categoryLabelAr,
                            'appliedAt' => optional($movement->created_at)->toIso8601String(),
                        ];
                    }

                    return $existingResults;
                }
            }

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
