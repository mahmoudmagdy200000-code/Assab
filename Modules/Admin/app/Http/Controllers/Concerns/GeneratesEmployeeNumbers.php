<?php

namespace Modules\Admin\Http\Controllers\Concerns;

use Modules\Admin\Models\Employee;

/**
 * Shared EMP-#### allocation. Both entry points that create employees — the
 * company branch screen (one at a time) and the admin roster upload (bulk) —
 * must draw from the same sequence, or the two would hand out the same number.
 */
trait GeneratesEmployeeNumbers
{
    /**
     * Highest EMP-#### suffix for the company + 1 (skips gaps; never reuses).
     *
     * asab_employees carries a unique (company_id, emp_number) index, so callers
     * are expected to retry on a UniqueConstraintViolationException rather than
     * treat this as a reservation.
     */
    protected function nextEmpNumber(?string $companyId): string
    {
        $max = Employee::withTrashed()
            // A platform admin uploading for a tenant carries no company of its
            // own, so the global scope would either be a no-op or (for a scoped
            // caller) hide the very rows that define the current maximum. Drop
            // it only when an explicit company narrows the query anyway.
            ->when(
                $companyId !== null,
                fn ($q) => $q->withoutGlobalScope('tenant')->where('company_id', $companyId),
            )
            ->where('emp_number', 'like', 'EMP-%')
            ->pluck('emp_number')
            ->map(fn ($n) => (int) substr((string) $n, 4))
            ->max() ?? 0;

        return 'EMP-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
