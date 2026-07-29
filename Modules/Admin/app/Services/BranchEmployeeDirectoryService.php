<?php

namespace Modules\Admin\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Admin\Models\Employee;
use Modules\Cashier\Models\Cashier;

/**
 * Branch employee directory (GET /company/me/branch/employees, §6.4).
 *
 * Two sources, one list: the dashboard's own `asab_employees` rows and the
 * cashiers the branch manager added from the MOBILE app for that same branch.
 * Cashier accounts are mobile-only — the dashboard stopped creating them — so a
 * mobile row is read-through and display-only: no emp number, no payroll
 * fields, `source = "mobile"` and `addedBy` = the manager who created it.
 *
 * A cashier already mirrored by a dashboard employee row (legacy_cashier_id,
 * created before cashier provisioning was removed) is emitted once, from the
 * employee side.
 */
class BranchEmployeeDirectoryService
{
    /** Per-source cap: a branch roster feeds a table, never a report. */
    private const SOURCE_CAP = 1000;

    /**
     * @param  array{search?: ?string, status?: ?string, page?: int, pageSize?: int}  $filters
     */
    public function paginate(?string $branchId, array $filters): LengthAwarePaginator
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max((int) ($filters['pageSize'] ?? 25), 1), 100);

        $rows = $branchId === null
            ? collect()
            : $this->dashboardRows($branchId, $filters)
                ->concat($this->mobileRows($branchId, $filters))
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page,
        );
    }

    /** @param array<string, mixed> $filters */
    private function dashboardRows(string $branchId, array $filters): Collection
    {
        $query = Employee::where('branch_id', $branchId);

        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('emp_number', 'like', "%{$search}%")
                ->orWhere('role', 'like', "%{$search}%"));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        return $query->orderBy('name')->limit(self::SOURCE_CAP)->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'empNumber' => $e->emp_number,
                'name' => $e->name,
                'role' => $e->role,
                'monthlySalary' => $e->monthly_salary,
                'shiftType' => $e->shift_type,
                'nationalId' => $e->national_id,
                'hireDate' => optional($e->hire_date)->toDateString(),
                'status' => $e->status,
                'email' => null,
                'phone' => $e->phone,
                'source' => 'dashboard',
                'addedBy' => null,
            ]);
    }

    /** @param array<string, mixed> $filters */
    private function mobileRows(string $branchId, array $filters): Collection
    {
        $alreadyMirrored = Employee::where('branch_id', $branchId)
            ->whereNotNull('legacy_cashier_id')
            ->pluck('legacy_cashier_id')
            ->all();

        $query = Cashier::with('creator:id,name')
            ->where('branch_id', $branchId)
            ->whereNotIn('id', $alreadyMirrored);

        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        return $query->orderBy('name')->limit(self::SOURCE_CAP)->get()
            ->map(fn (Cashier $c) => [
                'id' => $c->id,
                'empNumber' => null,
                'name' => $c->name,
                'role' => 'cashier',
                'monthlySalary' => null,
                'shiftType' => null,
                'nationalId' => null,
                'hireDate' => optional($c->created_at)->toDateString(),
                'status' => $c->status,
                'email' => $c->email,
                'phone' => $c->phone,
                'source' => 'mobile',
                'addedBy' => $c->creator?->name,
            ]);
    }
}
