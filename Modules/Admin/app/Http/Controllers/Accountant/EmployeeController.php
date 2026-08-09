<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Employee;
use Modules\Admin\Services\BrandBranchResolver;
use Modules\Admin\Services\EmployeeLedgerService;
use Modules\Admin\Support\EmployeeMovementCategory as Cat;
use Modules\Branch\Models\Branch;

/**
 * Accountant employee accounts + statements (SRS §7 ACC-7). Thin HTTP layer —
 * the ledger math lives in {@see EmployeeLedgerService}.
 */
class EmployeeController extends AsabController
{
    public function __construct(
        private readonly EmployeeLedgerService $ledger,
        private readonly BrandBranchResolver $brandBranches,
    ) {}

    /**
     * ACC-7.1 master list: signed balance + branch name per row, name search.
     * Filters: `brandId` (العلامة التجارية), `branchId` (الفرع), `empNumber`, `q`.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = $this->scopeToAssignedBranches(Employee::query());
            // Brand → branches resolves through the restaurant too; a brand whose
            // branches carry only `asab_restaurant_id` must not read as empty.
            $this->brandBranches->applyFilter($q, $request->query('brandId'));
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            if ($num = $request->query('empNumber')) {
                $q->where('emp_number', $num);
            }
            if ($needle = trim((string) $request->query('q', ''))) {
                $q->where('name', 'like', '%'.$needle.'%');
            }
            $p = $q->orderBy('name')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            $balances = $this->ledger->balancesFor(collect($p->items())->pluck('id'));
            $branchNames = $this->branchNames(collect($p->items())->pluck('branch_id'));

            return $this->paginated($p, array_map(function ($e) use ($balances, $branchNames) {
                $balance = $balances[$e->id] ?? 0;

                return [
                    'id' => $e->id, 'empNumber' => $e->emp_number, 'name' => $e->name, 'phone' => $e->phone,
                    'role' => $e->role, 'branchId' => $e->branch_id, 'branchName' => $branchNames[$e->branch_id] ?? null,
                    'monthlySalary' => $e->monthly_salary, 'status' => $e->status,
                    'balanceHalalas' => $balance, 'balanceCaption' => Cat::balanceCaption($balance),
                ];
            }, $p->items()));
        });
    }

    /** ACC-7.2 statement — month-bounded, per-row running balance, banner flag. */
    public function statement(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $employee = $this->scopeToAssignedBranches(Employee::query())->findOrFail($id);

            return $this->ok($this->ledger->statement(
                $employee,
                $request->query('month'),
                (int) $request->query('page', 1),
                (int) $request->query('pageSize', 50),
            ));
        });
    }

    /** ACC-7.4 add a manual movement (category-validated; system keys rejected). */
    public function addMovement(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $employee = $this->scopeToAssignedBranches(Employee::query())->findOrFail($id);
            $data = $request->validate([
                'movementType' => 'required|in:credit,debit',
                'amount' => 'required|integer|min:1',
                'category' => 'required|string|max:32',
                'description' => 'required|string|max:255',
                'date' => 'nullable|date',
            ]);
            $m = $this->ledger->addMovement($employee, $data, $request->user());

            return $this->created([
                'id' => $m->id, 'category' => $m->category, 'categoryLabelAr' => Cat::labelAr($m->category),
                'ref' => $m->ref, 'amountHalalas' => (int) $m->amount, 'movementType' => $m->movement_type,
            ]);
        });
    }

    /** ACC-7.3 «تسوية الرصيد» — clear the standing balance (or a partial amount). */
    public function settleBalance(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $employee = $this->scopeToAssignedBranches(Employee::query())->findOrFail($id);
            $data = $request->validate(['amountHalalas' => 'sometimes|integer|min:1']);

            return $this->ok($this->ledger->settleBalance($employee, $data['amountHalalas'] ?? null, $request->user()));
        });
    }

    /** @param  \Illuminate\Support\Collection<int,?string>  $ids */
    private function branchNames($ids): array
    {
        $ids = $ids->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Branch::whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
