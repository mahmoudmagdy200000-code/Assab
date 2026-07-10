<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;

/**
 * Accountant employee accounts + statements (BACKEND_API_SPEC.md §6.3.10).
 */
class EmployeeController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = $this->scopeToAssignedBranches(Employee::query());
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            if ($num = $request->query('empNumber')) {
                $q->where('emp_number', $num);
            }
            $p = $q->orderBy('name')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map(fn ($e) => [
                'id' => $e->id, 'empNumber' => $e->emp_number, 'name' => $e->name,
                'role' => $e->role, 'monthlySalary' => $e->monthly_salary, 'status' => $e->status,
            ], $p->items()));
        });
    }

    public function statement(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $employee = $this->scopeToAssignedBranches(Employee::query())->findOrFail($id);
            $movements = EmployeeMovement::where('employee_id', $id)->orderByDesc('movement_date')->get();
            $credit = (int) $movements->where('movement_type', 'credit')->sum('amount');
            $debit = (int) $movements->where('movement_type', 'debit')->sum('amount');

            return $this->ok([
                'employee' => ['id' => $employee->id, 'name' => $employee->name, 'empNumber' => $employee->emp_number],
                'balance' => $credit - $debit,
                'totalCredit' => $credit,
                'totalDebit' => $debit,
                'movements' => $movements->map(fn ($m) => [
                    'id' => $m->id,
                    'movementDate' => optional($m->movement_date)->toIso8601String(),
                    'description' => $m->description,
                    'movementType' => $m->movement_type,
                    'amount' => $m->amount,
                ])->all(),
            ]);
        });
    }

    public function addMovement(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $this->scopeToAssignedBranches(Employee::query())->findOrFail($id);
            $data = $request->validate([
                'movementType' => 'required|in:credit,debit',
                'amount' => 'required|integer|min:1',
                'description' => 'required|string|max:255',
                'date' => 'nullable|date',
            ]);
            $m = EmployeeMovement::create([
                'employee_id' => $id,
                'movement_type' => $data['movementType'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'movement_date' => $data['date'] ?? now(),
                'created_by_id' => $request->user()->id,
            ]);

            return $this->created(['id' => $m->id]);
        });
    }
}
