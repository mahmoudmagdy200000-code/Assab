<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\Concerns\GeneratesEmployeeNumbers;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\Cashier\Models\Cashier;

/**
 * Mobile → dashboard cashier mirror (the reverse of the retired dashboard
 * cashier provisioning).
 *
 * Cashier accounts are created in the mobile app by the branch manager. The
 * dashboard still needs an `asab_employees` counterpart for two reasons:
 *  - the branch manager's dashboard lists the people working in their branch;
 *  - BridgeLegacyCashierShift resolves the closing cashier by
 *    `asab_employees.legacy_cashier_id`, so without a mirror a mobile shift
 *    close never reaches the accountant inbox.
 *
 * The mirror carries no payroll data — `monthly_salary` starts at 0 and the
 * accountant fills it in on the dashboard; the mobile form never asks for it.
 */
class MobileCashierMirrorService
{
    use GeneratesEmployeeNumbers;

    /** Retries on the (company_id, emp_number) unique index, as the dashboard create does. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly BranchHierarchyLinker $branches,
        private readonly IdentityMapService $identity,
    ) {}

    /**
     * Ensure the dashboard employee row for a mobile cashier exists and is
     * linked. Returns null when the cashier's branch carries no dashboard
     * company (an unlinked legacy branch) — the branch directory still reads
     * such a cashier through, it simply has no ASAB record of its own.
     */
    public function mirror(Cashier $cashier): ?Employee
    {
        if ($cashier->branch_id === null) {
            return null;
        }

        $existing = Employee::withoutGlobalScope('tenant')
            ->where('legacy_cashier_id', $cashier->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $branch = Branch::whereKey($cashier->branch_id)->first();
        if ($branch === null) {
            return null;
        }

        // Heal the branch's asab_* tags first: without a company the employee
        // would be invisible to every tenant-scoped dashboard query.
        $this->branches->ensure($branch);
        $companyId = $branch->refresh()->asab_company_id;
        if ($companyId === null) {
            return null;
        }

        return $this->createWithRetry($cashier, $companyId);
    }

    private function createWithRetry(Cashier $cashier, string $companyId): Employee
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($cashier, $companyId) {
                    $employee = Employee::create([
                        'company_id' => $companyId,
                        'branch_id' => $cashier->branch_id,
                        'emp_number' => $this->nextEmpNumber($companyId),
                        'name' => $cashier->name,
                        'phone' => $cashier->phone,
                        'role' => 'cashier',
                        'monthly_salary' => 0, // set by the accountant; the mobile form has no salary field
                        'hire_date' => $cashier->created_at ?? now(),
                        'status' => 'active',
                    ]);
                    $employee->forceFill(['legacy_cashier_id' => $cashier->id])->save();

                    $this->identity->linkCashier($employee->id, $cashier->id, $companyId, $cashier->email, 'mobile');

                    return $employee;
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}
