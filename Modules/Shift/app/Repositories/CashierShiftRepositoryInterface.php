<?php

namespace Modules\Shift\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Shift\Models\CashierShift;
use Illuminate\Support\Collection;
use Carbon\Carbon;

interface CashierShiftRepositoryInterface
{
    /** Find cashier shift by ID with relations, or null. */
    public function findById(string $id): ?CashierShift;

    /** Pending list for branch manager: upcoming status, branch/cashier scoped, paginated */
    public function getUpcomingPaginated(string $branchId, ?string $cashierId, int $perPage = 10): LengthAwarePaginator;

    /** In-progress list for branch manager: branch/cashier scoped, paginated */
    public function getInProgressPaginated(string $branchId, ?string $cashierId, int $perPage = 10): LengthAwarePaginator;

    /** Single shift for manager show (branch-scoped); throws ModelNotFoundException if not found */
    public function findForManagerShow(string $id, string $branchId): CashierShift;

    /** Upcoming shifts for one cashier in a work week (branch-scoped), paginated */
    public function getUpcomingByCashierAndWeek(string $cashierId, string $branchId, Carbon $weekStart, Carbon $weekEnd, int $perPage = 10): LengthAwarePaginator;

    /** Pending (not started) shifts, optionally filtered by cashier and branch. */
    public function getPendingShifts(?string $cashierId = null, ?string $branchId = null): Collection;

    /** In-progress shifts (today), optionally filtered by cashier and branch. */
    public function getInProgressShifts(?string $cashierId = null, ?string $branchId = null): Collection;

    /** Completed shifts in optional date range, optionally filtered by cashier and branch. */
    public function getCompletedShifts(
        ?string $cashierId = null,
        ?string $branchId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null
    ): Collection;

    /** Reassigned shifts, optionally filtered by cashier and branch. */
    public function getReassignedShifts(?string $cashierId = null, ?string $branchId = null): Collection;

    /** Create a new cashier shift. */
    public function create(array $data): CashierShift;

    /** Update cashier shift. */
    public function update(CashierShift $shift, array $data): bool;

    /** Delete cashier shift. */
    public function delete(CashierShift $shift): bool;

    /** Shifts for a cashier on a given date. */
    public function getShiftsByCashierAndDate(string $cashierId, Carbon $date): Collection;

    /** Next not-started shift for cashier after given date. */
    public function getNextShift(string $cashierId, Carbon $afterDate): ?CashierShift;

    /** Whether the cashier has an overlapping not-started or in-progress shift on the date. */
    public function hasOverlappingShift(string $cashierId, string $shiftId, Carbon $date): bool;
}
