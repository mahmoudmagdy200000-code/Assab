<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\EmployeeSettlementRequest;
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

        $pendingSettlement = EmployeeSettlementRequest::where('employee_id', $employee->id)
            ->where('status', 'pending')
            ->latest('created_at')
            ->first();

        $latestSettlement = $pendingSettlement ?? EmployeeSettlementRequest::where('employee_id', $employee->id)
            ->latest('created_at')
            ->first();

        $settlementStatus = $pendingSettlement
            ? 'under_review'
            : ($latestSettlement?->status === 'approved' ? 'approved' : null);

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
            'hasDebitBalance' => $current < 0,
            'settlementStatus' => $settlementStatus,
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

        if ((string) $employee->company_id !== (string) $actor->company_id) {
            throw new AsabException('UNAUTHORIZED_TENANT', 'Employee belongs to a different company', 'الموظف يتبع شركة أخرى', 403);
        }

        return DB::transaction(function () use ($employee, $data, $category, $actor) {
            // Serialize against concurrent settlements and approvals on the same financial source, enforcing tenant alignment under lock.
            Employee::whereKey($employee->id)
                ->where('company_id', $actor->company_id)
                ->lockForUpdate()
                ->firstOrFail();

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
        });
    }

    /**
     * ACC-7.3 / STMT-02 / BE-09 «تسوية الرصيد» — creates a pending settlement request.
     * Standing balance is NOT credited until Head Accountant approval.
     *
     * @return array<string,mixed>
     */
    public function settleBalance(Employee $employee, ?int $amount, AsabUser $actor, string $reason, ?string $idempotencyKey = null): array
    {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '' || mb_strlen($trimmedReason) < 3) {
            throw new AsabException(
                'VALIDATION_ERROR',
                'A valid reason is mandatory for balance settlement',
                'سبب التسوية إلزامي',
                422,
                ['reason' => ['Reason must be at least 3 characters']],
            );
        }

        // Guarantee employee and company are properly bound within the same tenant scope
        if ((string) $employee->company_id !== (string) $actor->company_id) {
            throw new AsabException('UNAUTHORIZED_TENANT', 'Employee belongs to a different company', 'الموظف يتبع شركة أخرى', 403);
        }

        $idempotencyKey = (is_string($idempotencyKey) && trim($idempotencyKey) !== '') ? trim($idempotencyKey) : null;

        if ($idempotencyKey !== null && strlen($idempotencyKey) > 64) {
            throw new AsabException(
                'VALIDATION_ERROR',
                'Idempotency key must not exceed 64 characters',
                'مفتاح التكرار يجب ألا يتجاوز 64 حرفاً',
                422,
                ['idempotencyKey' => ['Maximum length is 64 characters']],
            );
        }

        if ($idempotencyKey) {
            $existing = EmployeeSettlementRequest::where('company_id', $employee->company_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                // The replay identity is scoped to the employee (resource): a key
                // bound to another employee must conflict, never leak that request.
                if ((string) $existing->employee_id !== (string) $employee->id) {
                    throw new AsabException(
                        'IDEMPOTENCY_KEY_REUSED',
                        'Idempotency-Key is already bound to another employee settlement',
                        'مفتاح التكرار مرتبط بتسوية موظف آخر',
                        409,
                    );
                }

                if (! $this->sameSettlementPayload($existing, $amount, $trimmedReason)) {
                    throw new AsabException(
                        'IDEMPOTENCY_KEY_REUSED',
                        'Idempotency-Key was reused with a different payload',
                        'تمت إعادة استخدام مفتاح التكرار بحمولة مختلفة',
                        409,
                    );
                }

                return $this->formatSettlementResponse($existing, $this->currentBalance($employee->id));
            }
        }

        return DB::transaction(function () use ($employee, $amount, $actor, $trimmedReason, $idempotencyKey) {
            // Serialize against settlements approval on the same financial source, enforcing tenant alignment under lock.
            $lockedEmployee = Employee::whereKey($employee->id)->where('company_id', $actor->company_id)->lockForUpdate()->firstOrFail();

            $balance = $this->currentBalance($employee->id);
            if ($balance === 0) {
                throw new AsabException('BALANCE_ALREADY_SETTLED', 'Balance is already zero', 'الرصيد صفر بالفعل', 409);
            }

            $magnitude = abs($balance);
            $isFullSettlement = ($amount === null);
            $settle = $isFullSettlement ? $magnitude : (int) $amount;
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

            try {
                $request = EmployeeSettlementRequest::create([
                    'company_id' => $employee->company_id,
                    'employee_id' => $employee->id,
                    'amount_halalas' => $settle,
                    'is_full_settlement' => $isFullSettlement,
                    'original_balance_halalas' => $balance,
                    'direction' => $type,
                    'reason' => $trimmedReason,
                    'status' => 'pending',
                    'created_by_user_id' => $actor->id,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle concurrent race condition on composite unique index (company_id, idempotency_key)
                if ($idempotencyKey && ($e->getCode() === '23000' || str_contains($e->getMessage(), 'asab_emp_settle_comp_idemp_unique') || str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'UNIQUE constraint failed'))) {
                    $existing = EmployeeSettlementRequest::where('company_id', $employee->company_id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($existing) {
                        if ((string) $existing->employee_id !== (string) $employee->id) {
                            throw new AsabException(
                                'IDEMPOTENCY_KEY_REUSED',
                                'Idempotency-Key is already bound to another employee settlement',
                                'مفتاح التكرار مرتبط بتسوية موظف آخر',
                                409,
                            );
                        }

                        if ($this->sameSettlementPayload($existing, $amount, $trimmedReason)) {
                            return $this->formatSettlementResponse($existing, $balance);
                        }
                    }
                    throw new AsabException(
                        'IDEMPOTENCY_KEY_REUSED',
                        'Idempotency-Key was reused with a different payload',
                        'تمت إعادة استخدام مفتاح التكرار بحمولة مختلفة',
                        409
                    );
                }
                throw $e;
            }

            return $this->formatSettlementResponse($request, $balance);
        });
    }

    /**
     * BE-09 / STMT-02 Head Accountant approval: posts opposing ledger movement and marks request approved.
     *
     * @return array<string,mixed>
     */
    public function approveSettlement(string $id, AsabUser $reviewer): array
    {
        // 1. Resolve employee ID outside the transaction to prevent establishing
        // a stale MVCC read-view in MySQL/InnoDB REPEATABLE READ prior to acquiring the lock.
        $initial = EmployeeSettlementRequest::where('company_id', $reviewer->company_id)->findOrFail($id);
        $employeeId = $initial->employee_id;

        return DB::transaction(function () use ($id, $employeeId, $reviewer) {
            // 2. Strict lock hierarchy: Employee first as the root financial resource, verifying company alignment under lock.
            $employee = Employee::whereKey($employeeId)->where('company_id', $reviewer->company_id)->lockForUpdate()->firstOrFail();

            // 3. Re-read and lock the settlement request under the employee lock, verifying company scoping and employee binding.
            $req = EmployeeSettlementRequest::where('company_id', $reviewer->company_id)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($req === null || $req->status !== 'pending') {
                throw new AsabException('REQUEST_ALREADY_PROCESSED', 'Settlement request is already '.($req->status ?? 'processed'), 'تمت معالجة طلب التسوية مسبقاً', 409);
            }

            if ((string) $req->employee_id !== (string) $employeeId) {
                throw new AsabException('VALIDATION_ERROR', 'Settlement employee mismatch', 'تعارض في بيانات موظف التسوية', 422);
            }

            $balance = $this->currentBalance($req->employee_id);
            $amount = (int) $req->amount_halalas;

            // Stale revalidation: the request may only post while the outstanding
            // balance still covers its amount in the direction it was created for.
            if ($balance === 0) {
                throw new AsabException('BALANCE_ALREADY_SETTLED', 'Employee balance is already zero; this settlement request is stale and must be resubmitted', 'الرصيد صفر بالفعل — طلب التسوية قديم ويجب إعادة إنشائه', 409);
            }

            // Direction verification: Compare current direction to the immutable direction snapshotted in the request.
            $currentDirection = $balance < 0 ? 'credit' : 'debit';
            if ($currentDirection !== $req->direction) {
                throw new AsabException('SETTLEMENT_DIRECTION_CHANGED', 'The balance direction changed since this request was created; it is stale and must be resubmitted', 'اتجاه الرصيد تغيّر بعد إنشاء الطلب — الطلب قديم ويجب إعادة إنشائه', 409);
            }

            // Full settlement intent protection: If the request was created as a FULL settlement,
            // ANY balance fluctuation (increase or decrease) invalidates the full settlement intent.
            // Implicit partial settlement is strictly prohibited.
            if ($req->is_full_settlement && abs($balance) !== $amount) {
                throw new AsabException(
                    'SETTLEMENT_BALANCE_CHANGED',
                    'The employee balance changed after this full settlement request was created; it must be resubmitted',
                    'تغير رصيد الموظف بعد إنشاء طلب التسوية الكاملة — يجب إعادة تقديم الطلب',
                    409
                );
            }

            // For partial settlement requests: outstanding balance must at least cover the requested amount.
            if (! $req->is_full_settlement && abs($balance) < $amount) {
                throw new AsabException('SETTLEMENT_BALANCE_CHANGED', 'The outstanding balance no longer covers this settlement request; it is stale and must be resubmitted', 'الرصيد القائم لم يعد يغطي طلب التسوية — الطلب قديم ويجب إعادة إنشائه', 409);
            }

            // Debtor (negative) → credit clears it; creditor (positive) → debit.
            $type = $currentDirection;
            $movement = EmployeeMovement::create([
                'employee_id' => $req->employee_id,
                'movement_type' => $type,
                'amount' => $req->amount_halalas,
                'category' => 'settlement',
                'ref' => Cat::makeRef('settlement', (string) Str::uuid()),
                'description' => 'تسوية رصيد معتمدة — '.$employee->name.' — '.$req->reason,
                'movement_date' => now(),
                'created_by_id' => $reviewer->id,
            ]);

            $req->update([
                'status' => 'approved',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'movement_id' => $movement->id,
            ]);

            $newBalance = $this->currentBalance($req->employee_id);

            $res = $this->formatSettlementResponse($req, $newBalance);
            $res['movementType'] = $type;
            $res['category'] = 'settlement';
            $res['categoryLabelAr'] = Cat::labelAr('settlement');
            $res['ref'] = $movement->ref;

            return $res;
        });
    }

    /**
     * BE-09 / STMT-02 Head Accountant rejection: transitions status to rejected without ledger posting.
     *
     * @return array<string,mixed>
     */
    public function rejectSettlement(string $id, ?string $rejectionReason, AsabUser $reviewer): array
    {
        return DB::transaction(function () use ($id, $rejectionReason, $reviewer) {
            $req = EmployeeSettlementRequest::where('company_id', $reviewer->company_id)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($req->status !== 'pending') {
                throw new AsabException('REQUEST_ALREADY_PROCESSED', 'Settlement request is already '.$req->status, 'تمت معالجة طلب التسوية مسبقاً', 409);
            }

            $req->update([
                'status' => 'rejected',
                'rejection_reason' => $rejectionReason,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            return $this->formatSettlementResponse($req);
        });
    }

    /**
     * Canonical replay comparison for a reused settlement idempotency key:
     * When $amount is null (full settlement), matches if existing was also full settlement,
     * regardless of whether current balance has since fluctuated.
     */
    private function sameSettlementPayload(EmployeeSettlementRequest $existing, ?int $amount, string $reason): bool
    {
        if (trim($existing->reason ?? '') !== trim($reason)) {
            return false;
        }

        // Requirement 3: Preserve original settlement intent when amount is omitted (is_full_settlement),
        // without comparing it against fluctuating balance at replay time.
        if ($amount === null) {
            return (bool) $existing->is_full_settlement;
        }

        if ($existing->is_full_settlement) {
            return false;
        }

        return (int) $existing->amount_halalas === (int) $amount;
    }

    public function formatSettlementResponse(EmployeeSettlementRequest $req, ?int $balance = null): array
    {
        return [
            'id' => $req->id,
            'settlementId' => $req->id,
            'companyId' => $req->company_id,
            'employeeId' => $req->employee_id,
            'amountHalalas' => (int) $req->amount_halalas,
            'isFullSettlement' => (bool) $req->is_full_settlement,
            'originalBalanceHalalas' => (int) $req->original_balance_halalas,
            'direction' => $req->direction,
            'reason' => $req->reason,
            'status' => $req->status,
            'rejectionReason' => $req->rejection_reason,
            'createdByUserId' => $req->created_by_user_id,
            'reviewedByUserId' => $req->reviewed_by_user_id,
            'reviewedAt' => $req->reviewed_at?->toIso8601String(),
            'movementId' => $req->movement_id,
            'balanceModified' => $req->status === 'approved',
            'balanceHalalas' => $balance !== null ? $balance : $this->currentBalance($req->employee_id),
            'createdAt' => $req->created_at?->toIso8601String(),
        ];
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
