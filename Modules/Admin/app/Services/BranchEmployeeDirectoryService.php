<?php

namespace Modules\Admin\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Admin\Models\Employee;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;

/**
 * Branch employee directory (GET /company/me/branch/employees, §6.4).
 *
 * Two sources, one list: the dashboard's own `asab_employees` rows and the
 * cashiers the branch manager added from the MOBILE app for that same branch.
 * Cashier accounts are mobile-only — the dashboard stopped creating them — so a
 * cashier row carries the data the mobile form actually collects (email, phone,
 * assigned shift windows, who created it and when) and leaves the dashboard-only
 * payroll fields empty for the accountant to fill in.
 *
 * A mobile cashier normally has an `asab_employees` mirror (legacy_cashier_id,
 * written by MirrorMobileCashierToEmployee); one that predates the mirror is
 * read through directly, so nobody disappears from the roster.
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
            : $this->rowsFor($branchId, $filters);

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page,
        );
    }

    /** @param array<string, mixed> $filters */
    private function rowsFor(string $branchId, array $filters): Collection
    {
        $employees = $this->employees($branchId, $filters);
        $mirrored = $employees->pluck('legacy_cashier_id')->filter()->all();
        $cashiers = $this->cashiers($branchId, $filters, $mirrored);

        // One context lookup for both halves: the mobile-side detail (shifts,
        // creator, contact) belongs to the cashier row either way.
        $context = $this->mobileContext(
            array_merge($mirrored, $cashiers->pluck('id')->all()),
            $branchId,
        );

        return $employees
            ->map(fn (Employee $e) => $this->employeeRow($e, $context))
            ->concat($cashiers->map(fn (Cashier $c) => $this->cashierRow($c, $context)))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** @param array<string, mixed> $filters */
    private function employees(string $branchId, array $filters): Collection
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

        return $query->orderBy('name')->limit(self::SOURCE_CAP)->get();
    }

    /**
     * Mobile cashiers of the branch that have no dashboard mirror yet.
     *
     * @param  array<string, mixed>  $filters
     * @param  string[]  $mirrored
     */
    private function cashiers(string $branchId, array $filters, array $mirrored): Collection
    {
        $query = Cashier::where('branch_id', $branchId)->whereNotIn('id', $mirrored);

        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        return $query->orderBy('name')->limit(self::SOURCE_CAP)->get();
    }

    /**
     * Mobile-side detail per cashier id: contact, creator, and the shift windows
     * they are assigned to (the app's "Shift Details" card).
     *
     * @param  string[]  $cashierIds
     * @return Collection<string, array<string, mixed>>
     */
    private function mobileContext(array $cashierIds, string $branchId): Collection
    {
        $cashierIds = array_values(array_unique(array_filter($cashierIds)));
        if ($cashierIds === []) {
            return collect();
        }

        $cashiers = Cashier::with('creator:id,name')->whereIn('id', $cashierIds)->get();

        $shifts = CashierShift::whereIn('cashier_id', $cashierIds)
            ->with('shift:id,name,start_time,end_time,branch_id')
            ->get()
            ->groupBy('cashier_id');

        return $cashiers->mapWithKeys(fn (Cashier $c) => [$c->id => [
            'email' => $c->email,
            'phone' => $c->phone,
            'cashierStatus' => $c->status,
            'addedBy' => $c->creator?->name,
            'addedAt' => optional($c->created_at)->toIso8601String(),
            'workingShifts' => ($shifts[$c->id] ?? collect())
                ->pluck('shift')
                ->filter()
                ->unique('id')
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'startTime' => $s->start_time?->format('H:i'),
                    'endTime' => $s->end_time?->format('H:i'),
                ])
                ->values()
                ->all(),
        ]]);
    }

    /** @param Collection<string, array<string, mixed>> $context */
    private function employeeRow(Employee $e, Collection $context): array
    {
        $mobile = $e->legacy_cashier_id ? $context->get($e->legacy_cashier_id) : null;

        return [
            'id' => $e->id,
            'empNumber' => $e->emp_number,
            'name' => $e->name,
            'role' => $e->role,
            // Payroll is dashboard-entered: the mobile add-cashier form has no
            // salary field, so a mirrored cashier starts at 0 until the
            // accountant sets it.
            'monthlySalary' => $e->monthly_salary,
            'shiftType' => $e->shift_type,
            'nationalId' => $e->national_id,
            'hireDate' => optional($e->hire_date)->toDateString(),
            'status' => $e->status,
            'email' => $mobile['email'] ?? null,
            'phone' => $e->phone ?? ($mobile['phone'] ?? null),
            'source' => $mobile !== null ? 'mobile' : 'dashboard',
            'cashierId' => $e->legacy_cashier_id,
            'workingShifts' => $mobile['workingShifts'] ?? [],
            'addedBy' => $mobile['addedBy'] ?? null,
            'addedAt' => $mobile['addedAt'] ?? optional($e->created_at)->toIso8601String(),
        ];
    }

    /** @param Collection<string, array<string, mixed>> $context */
    private function cashierRow(Cashier $c, Collection $context): array
    {
        $mobile = $context->get($c->id, []);

        return [
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
            'cashierId' => $c->id,
            'workingShifts' => $mobile['workingShifts'] ?? [],
            'addedBy' => $mobile['addedBy'] ?? null,
            'addedAt' => optional($c->created_at)->toIso8601String(),
        ];
    }
}
