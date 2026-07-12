<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Support\EmployeeMovementCategory as Cat;

/**
 * SRS §7 ACC-7 — the read/write core of the employee account ledger: signed
 * balances for the master list, the month-bounded statement with a per-row
 * running balance, manual movements (category-validated), and «تسوية الرصيد».
 *
 * Balance convention (SRS §6): balance = Σcredit − Σdebit. A negative balance
 * means the employee owes the company («مديون للشركة»).
 */
class EmployeeLedgerService
{
    /**
     * Signed standing balance (all-time) for many employees in one query.
     * CASE-WHEN aggregation is identical on MySQL and SQLite.
     *
     * @param  iterable<string>  $employeeIds
     * @return array<string,int> employeeId → balance halalas
     */
    public function balancesFor(iterable $employeeIds): array
    {
        $ids = collect($employeeIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return EmployeeMovement::whereIn('employee_id', $ids)
            ->selectRaw("employee_id, SUM(CASE WHEN movement_type = 'credit' THEN amount ELSE -amount END) as bal")
            ->groupBy('employee_id')
            ->pluck('bal', 'employee_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** The one employee's signed standing balance (all movements). */
    public function currentBalance(string $employeeId): int
    {
        return $this->balancesFor([$employeeId])[$employeeId] ?? 0;
    }

    /**
     * ACC-7.2 statement: opening balance carried from before the period, each
     * movement's running balance, the closing balance, and the auto-deduction
     * banner flag (keyed off the *current* standing balance, not the period).
     *
     * @return array<string,mixed>
     */
    public function statement(Employee $employee, ?string $month, int $page = 1, int $pageSize = 50): array
    {
        $pageSize = max(1, min($pageSize, 200));
        [$start, $end, $monthKey] = $this->period($month);

        $base = EmployeeMovement::where('employee_id', $employee->id);

        // Opening balance = net of everything strictly before the period window.
        $opening = $month
            ? (int) (clone $base)->where('movement_date', '<', $start)
                ->selectRaw("COALESCE(SUM(CASE WHEN movement_type = 'credit' THEN amount ELSE -amount END), 0) as bal")
                ->value('bal')
            : 0;

        $windowed = (clone $base)
            ->when($month, fn ($q) => $q->whereBetween('movement_date', [$start, $end]));

        // Running balance is a full-period scan (small per month); compute it over
        // the whole ascending set so every page carries the correct figure, then
        // slice for display. Hard cap guards the unbounded case.
        $asc = (clone $windowed)->orderBy('movement_date')->orderBy('id')->limit(2000)->get();
        $total = $asc->count();

        $running = $opening;
        $all = [];
        foreach ($asc as $m) {
            $running += $m->movement_type === 'credit' ? (int) $m->amount : -(int) $m->amount;
            $all[] = [
                'id' => $m->id,
                'movementDate' => optional($m->movement_date)->toIso8601String(),
                'description' => $m->description,
                'movementType' => $m->movement_type,
                'movementTypeLabelAr' => $m->movement_type === 'credit' ? 'دائن' : 'مدين',
                'category' => $m->category,
                'categoryLabelAr' => Cat::labelAr($m->category),
                'ref' => $m->ref,
                'amountHalalas' => (int) $m->amount,
                'amount' => (int) $m->amount, // legacy alias
                'runningBalanceHalalas' => $running,
            ];
        }
        $closing = $running;
        // Newest-first for display, then paginate the slice.
        $rows = array_slice(array_reverse($all), ($page - 1) * $pageSize, $pageSize);

        $current = $this->currentBalance($employee->id);

        return [
            'employee' => [
                'id' => $employee->id, 'name' => $employee->name,
                'empNumber' => $employee->emp_number, 'branchId' => $employee->branch_id,
            ],
            'period' => ['month' => $monthKey, 'from' => $start->toDateString(), 'to' => $end->toDateString()],
            'openingBalanceHalalas' => $opening,
            'closingBalanceHalalas' => $closing,
            // Standing all-time balance — the master-list figure + banner source.
            'balance' => $current,
            'balanceHalalas' => $current,
            'balanceCaption' => Cat::balanceCaption($current),
            'autoDeductFromSalary' => $current < 0,
            'totalCredit' => (int) (clone $windowed)->where('movement_type', 'credit')->sum('amount'),
            'totalDebit' => (int) (clone $windowed)->where('movement_type', 'debit')->sum('amount'),
            'movementCount' => $total,
            'movements' => $rows,
            'meta' => [
                'page' => $page, 'pageSize' => $pageSize, 'total' => $total,
                'totalPages' => (int) ceil($total / $pageSize),
            ],
        ];
    }

    /**
     * ACC-7.4 — post one manual movement. System categories are rejected: a
     * sales/cash/waste/inventory charge may only enter through its operation flow.
     *
     * @param  array{movementType:string, amount:int, category:string, description:string, date?:string|null}  $data
     */
    public function addMovement(Employee $employee, array $data, AsabUser $actor): EmployeeMovement
    {
        $category = $data['category'];
        if (! Cat::isManual($category)) {
            throw new AsabException(
                'VALIDATION_ERROR',
                'Unknown or system-managed category',
                'تصنيف غير معروف أو خاص بالنظام',
                422,
                ['category' => ['must be one of: '.implode(', ', Cat::manualKeys())]],
            );
        }

        return EmployeeMovement::create([
            'employee_id' => $employee->id,
            'movement_type' => $data['movementType'],
            'amount' => (int) $data['amount'],
            'category' => $category,
            'ref' => Cat::makeRef($category, (string) Str::uuid()),
            'description' => $data['description'],
            'movement_date' => $data['date'] ?? now(),
            'created_by_id' => $actor->id,
        ]);
    }

    /**
     * ACC-7.3 «تسوية الرصيد» — post an opposing `settlement` movement that clears
     * the standing balance (or a partial amount of it). A debtor (balance < 0)
     * is settled with a credit; a creditor with a debit. 409 when already zero.
     *
     * @return array<string,mixed>
     */
    public function settleBalance(Employee $employee, ?int $amount, AsabUser $actor): array
    {
        return DB::transaction(function () use ($employee, $amount, $actor) {
            $balance = $this->currentBalance($employee->id);
            if ($balance === 0) {
                throw new AsabException('BALANCE_ALREADY_SETTLED', 'Balance is already zero', 'الرصيد صفر بالفعل', 409);
            }

            $magnitude = abs($balance);
            $settle = $amount !== null ? (int) $amount : $magnitude;
            if ($settle < 1 || $settle > $magnitude) {
                throw new AsabException(
                    'VALIDATION_ERROR',
                    'Settlement amount must be between 1 and the outstanding balance',
                    'قيمة التسوية يجب أن تكون بين 1 والرصيد القائم',
                    422,
                    ['amountHalalas' => ["outstanding {$magnitude} halalas"]],
                );
            }

            // Debtor (negative) → credit clears it; creditor (positive) → debit.
            $type = $balance < 0 ? 'credit' : 'debit';
            $movement = EmployeeMovement::create([
                'employee_id' => $employee->id,
                'movement_type' => $type,
                'amount' => $settle,
                'category' => 'settlement',
                'ref' => Cat::makeRef('settlement', (string) Str::uuid()),
                'description' => 'تسوية رصيد — '.$employee->name,
                'movement_date' => now(),
                'created_by_id' => $actor->id,
            ]);

            $newBalance = $this->currentBalance($employee->id);

            return [
                'id' => $movement->id,
                'employeeId' => $employee->id,
                'settledHalalas' => $settle,
                'movementType' => $type,
                'category' => 'settlement',
                'categoryLabelAr' => Cat::labelAr('settlement'),
                'ref' => $movement->ref,
                'balanceHalalas' => $newBalance,
                'balanceCaption' => Cat::balanceCaption($newBalance),
            ];
        });
    }

    /**
     * Resolve a YYYY-MM window. No month → the full range (a sentinel wide span
     * the caller treats as "all", with the current month echoed for display).
     *
     * @return array{0:Carbon,1:Carbon,2:string}
     */
    private function period(?string $month): array
    {
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

            return [$start, (clone $start)->endOfMonth(), $month];
        }

        // "All" — span everything; echo the current month as the label.
        return [Carbon::createFromFormat('Y-m-d', '2000-01-01')->startOfDay(), now()->endOfMonth(), now()->format('Y-m')];
    }
}
