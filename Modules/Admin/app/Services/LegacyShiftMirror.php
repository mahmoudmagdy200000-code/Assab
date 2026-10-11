<?php

namespace Modules\Admin\Services;

use App\Support\ShiftFinancialCalculator;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Shift;
use Modules\Branch\Models\Branch;
use Modules\Shift\Models\CashierShift as LegacyShift;

/**
 * Mobile shift → `asab_shifts` mirror (MOB-1.6, live half).
 *
 * A mobile shift used to reach the dashboard only at CLOSE time, so the
 * accountant's «مباشر» board (GET /accountant/shifts/live reads `asab_shifts`
 * where status ∈ active|late) was permanently empty while cashiers were
 * actually working. This opens the mirror row when the shift STARTS; the close
 * bridge then finishes the same row instead of creating a second one.
 *
 * Keyed on `legacy_shift_id`, so re-firing the event is idempotent.
 */
class LegacyShiftMirror
{
    public function __construct(
        private readonly BranchHierarchyLinker $branches,
        private readonly RealtimeBroadcaster $rt,
    ) {}

    /** The mirror row for a legacy shift, whatever its status. */
    public function existing(string $legacyShiftId): ?Shift
    {
        return Shift::withoutGlobalScopes()->where('legacy_shift_id', $legacyShiftId)->first();
    }

    /**
     * The ASAB employee mirroring the legacy cashier — the row that carries the
     * branch/company the dashboard scopes by. Null when the cashier has no
     * dashboard counterpart yet (see `asab:mirror-mobile-cashiers`).
     */
    public function employeeFor(LegacyShift $legacy): ?Employee
    {
        $employee = Employee::withoutGlobalScopes()
            ->where('legacy_cashier_id', $legacy->cashier_id)
            ->first();

        if ($employee === null || $employee->branch_id === null) {
            return null;
        }

        // A branch missing its brand tag would hide the shift from the very
        // accountant responsible for it (their scope is brand → branches).
        $branch = Branch::whereKey($employee->branch_id)->first();
        if ($branch !== null) {
            $this->branches->ensure($branch);
        }

        return $employee;
    }

    /**
     * Open (or refresh) the live mirror row for a running mobile shift.
     * Returns null when there is no dashboard counterpart to attach it to.
     */
    public function open(LegacyShift $legacy): ?Shift
    {
        $existing = $this->existing($legacy->id);
        if ($existing !== null) {
            return $existing; // already mirrored (or already closed) — nothing to reopen
        }

        $employee = $this->employeeFor($legacy);
        if ($employee === null) {
            return null;
        }

        $shift = Shift::create([
            'company_id' => $employee->company_id,
            'branch_id' => $employee->branch_id,
            'cashier_employee_id' => $employee->id,
            'cashier_name' => $employee->name,
            'shift_type' => $legacy->shift?->name ?? 'مسائي',
            'started_at' => $legacy->actual_start_time ?? now(),
            'status' => 'active',
            'orders_count' => 0,
            'sales_amount' => 0,
            'opening_float' => ShiftFinancialCalculator::storedSarToHalalas($legacy->opening_balance),
            'legacy_shift_id' => $legacy->id,
        ]);

        $this->rt->shiftChanged($shift, 'started');

        return $shift;
    }
}
