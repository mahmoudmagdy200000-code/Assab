<?php

namespace Modules\Admin\Services;

use App\Support\ShiftFinancialCalculator;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Shift;
use Modules\Admin\Support\ShiftEnums;
use Modules\Branch\Models\Branch;
use Modules\Shift\Models\BranchManagerShift;

/**
 * Branch-manager workday → `asab_shifts` mirror (the manager half of MOB-1.6).
 *
 * The live board reads `asab_shifts` where status ∈ active|late, and only the
 * CASHIER bridge ever wrote into it — so a manager who pressed «بدء الشيفت» in
 * the mobile app was invisible on the dashboard while actually running the
 * branch. This opens a `role='branch_manager'` row at start and finishes it at
 * end, keyed on `legacy_shift_id` so re-firing the event is idempotent.
 *
 * The mirror is deliberately display-only:
 *  - `sales_amount` stays 0. A manager's `total_sales` is the SUM of the branch's
 *    cashier shifts, which are already mirrored one row each; carrying it here
 *    would double every sales KPI on the board.
 *  - it never walks the SHF- close pipeline. The manager's figures reach the
 *    accountant as the daily sales statement (BridgeManagerDailyClose), and a
 *    second review lane for the same money is exactly the duplication the
 *    accountant complained about.
 */
class LegacyManagerShiftMirror
{
    public function __construct(
        private readonly BranchHierarchyLinker $branches,
        private readonly RealtimeBroadcaster $rt,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    /** The mirror row for a legacy manager shift, whatever its status. */
    public function existing(string $managerShiftId): ?Shift
    {
        return Shift::withoutGlobalScopes()
            ->where('legacy_shift_id', $managerShiftId)
            ->where('role', Shift::ROLE_BRANCH_MANAGER)
            ->first();
    }

    /**
     * Open (or return) the live mirror row for a running manager workday.
     * Returns null when the branch carries no ASAB company link — the row would
     * then belong to no tenant and no accountant could ever see it.
     */
    public function open(BranchManagerShift $legacy): ?Shift
    {
        $existing = $this->existing($legacy->id);
        if ($existing !== null) {
            return $existing;
        }

        $branch = $this->resolveBranch($legacy);
        if ($branch === null) {
            return null;
        }

        $legacy->loadMissing('branchManager:id,name,phone');
        $employee = $this->employeeFor($legacy);

        $shift = Shift::create([
            'company_id' => $branch->asab_company_id,
            'branch_id' => $branch->id,
            'role' => Shift::ROLE_BRANCH_MANAGER,
            // The board renders `cashierName`; carrying the manager there is what
            // makes them visible without an FE change. `role` tells them apart.
            'cashier_employee_id' => $employee?->id,
            'cashier_name' => $legacy->branchManager?->name,
            'supervisor_name' => $legacy->branchManager?->name,
            'shift_type' => ShiftEnums::MANAGER_DAY_TYPE_AR,
            'started_at' => $legacy->actual_start_time ?? now(),
            'status' => 'active',
            'orders_count' => 0,
            'sales_amount' => 0,
            'opening_float' => $this->toHalalas($legacy->opening_balance),
            'legacy_shift_id' => $legacy->id,
        ]);

        $this->rt->shiftChanged($shift, 'started');

        return $shift;
    }

    /**
     * Finish the mirror row when the manager ends their workday. Idempotent:
     * a row already closed is left alone.
     */
    public function close(BranchManagerShift $legacy): ?Shift
    {
        $shift = $this->existing($legacy->id) ?? $this->open($legacy);
        if ($shift === null || ! in_array($shift->status, ['active', 'late'], true)) {
            return $shift;
        }

        $shift->update([
            'status' => 'closed',
            'ended_at' => $legacy->actual_end_time ?? now(),
            // No cash figures: the manager's money is reviewed as the daily sales
            // statement, and writing a variance here would put a phantom gap in
            // the accountant's «فروق الكاش» count.
            'notes' => $legacy->handover_notes,
        ]);

        $this->rt->shiftChanged($shift->fresh(), 'closed');

        return $shift->fresh();
    }

    /**
     * The branch the manager runs, with its ASAB hierarchy tags healed — a
     * branch missing `asab_brand_id` reaches the head but never the scoped
     * accountant responsible for it.
     */
    private function resolveBranch(BranchManagerShift $legacy): ?Branch
    {
        $branchId = $legacy->branch_id ?? $legacy->branchManager?->branch_id;
        $branch = $branchId ? Branch::whereKey($branchId)->first() : null;

        if ($branch === null) {
            $this->log->warning('manager-shift-mirror: skipped — no branch on the manager shift', [
                'manager_shift_id' => $legacy->id, 'branch_id' => $branchId,
                'reason' => 'BRANCH_MISSING',
            ]);

            return null;
        }

        $this->branches->ensure($branch);
        $branch->refresh();

        if ($branch->asab_company_id === null) {
            $this->log->warning('manager-shift-mirror: skipped — branch has no ASAB company link', [
                'manager_shift_id' => $legacy->id, 'branch_id' => $branch->id,
                'reason' => 'BRANCH_NOT_LINKED',
                'fix' => 'link the branch (PATCH /admin/branches/{id} restaurantId), then php artisan asab:bridge-backfill',
            ]);

            return null;
        }

        return $branch;
    }

    /**
     * The manager's roster row, so the board's contact modal gets a phone.
     * Read-only: the identity map's dashboard user first, then the branch's
     * manager-role row by name. Null is fine — the mirror still opens.
     */
    private function employeeFor(BranchManagerShift $legacy): ?Employee
    {
        $asabUserId = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRANCH_MANAGER)
            ->where('legacy_id', $legacy->branch_manager_id)
            ->value('dashboard_id');

        if ($asabUserId !== null) {
            $employee = Employee::withoutGlobalScopes()->where('asab_user_id', $asabUserId)->first();
            if ($employee !== null) {
                return $employee;
            }
        }

        $name = trim((string) $legacy->branchManager?->name);
        if ($name === '') {
            return null;
        }

        return Employee::withoutGlobalScopes()
            ->where('branch_id', $legacy->branch_id)
            ->where('name', $name)
            ->first();
    }

    private function toHalalas(mixed $sar): int
    {
        return ShiftFinancialCalculator::storedSarToHalalas($sar);
    }
}
