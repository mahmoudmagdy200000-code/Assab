<?php

namespace Modules\Shift\Repositories;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;

/**
 * Repository for CashierShift data access. Implements CashierShiftRepositoryInterface.
 */
class CashierShiftRepository implements CashierShiftRepositoryInterface
{
    /**
     * Find cashier shift by ID with relations, or null.
     */
    public function findById(string $id): ?CashierShift
    {
        return CashierShift::with([
            'cashier',
            'shift',
            'nextCashier',
            'salesBreakdown.aggregator',
            'handoverStatus',
            'varianceDetails',
        ])->find($id);
    }

    /**
     * Pending list for branch manager: upcoming status, branch/cashier scoped, paginated.
     */
    public function getUpcomingPaginated(string $branchId, ?string $cashierId, int $perPage = 10): LengthAwarePaginator
    {
        $query = CashierShift::upcoming()
            ->with(['cashier', 'shift', 'nextCashier', 'originalCashier', 'reassignedBy', 'handover', 'handoverStatus'])
            ->whereHas('shift', fn ($q) => $q->where('branch_id', $branchId))
            ->whereHas('cashier', fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('shift_date', '>=', now()->subMonth())
            ->whereDate('shift_date', '<=', now()->addMonth())
            ->orderBy('shift_date');

        if ($cashierId !== null) {
            $query->where('cashier_id', $cashierId);
        }

        return $query->paginate($perPage);
    }

    /**
     * In-progress list for branch manager: branch/cashier scoped, paginated.
     */
    public function getInProgressPaginated(string $branchId, ?string $cashierId, int $perPage = 10): LengthAwarePaginator
    {
        $query = CashierShift::inProgress()
            ->with(['cashier', 'shift', 'handover', 'handoverStatus'])
            ->whereHas('shift', fn ($q) => $q->where('branch_id', $branchId))
            ->whereHas('cashier', fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('actual_start_time');

        if ($cashierId !== null) {
            $query->where('cashier_id', $cashierId);
        }

        return $query->paginate($perPage);
    }

    /**
     * Single shift for manager show (branch-scoped). Throws ModelNotFoundException if not found.
     */
    public function findForManagerShow(string $id, string $branchId): CashierShift
    {
        return CashierShift::with([
            'cashier',
            'shift',
            'nextCashier',
            'originalCashier',
            'reassignedBy',
            'salesBreakdown.aggregator',
            'handoverStatus.reviewedBy',
            'handover',
            'varianceDetails.responsibleCashier',
            'varianceAlerts',
            'history',
        ])
            ->whereHas('shift', fn ($q) => $q->where('branch_id', $branchId))
            ->whereHas('cashier', fn ($q) => $q->where('branch_id', $branchId))
            ->findOrFail($id);
    }

    /**
     * Upcoming shifts for one cashier in a work week (branch-scoped), paginated.
     */
    public function getUpcomingByCashierAndWeek(string $cashierId, string $branchId, Carbon $weekStart, Carbon $weekEnd, int $perPage = 10): LengthAwarePaginator
    {
        return CashierShift::upcoming()
            ->where('cashier_id', $cashierId)
            ->whereDate('shift_date', '>=', $weekStart)
            ->whereDate('shift_date', '<=', $weekEnd)
            ->whereHas('shift', fn ($q) => $q->where('branch_id', $branchId))
            ->whereHas('cashier', fn ($q) => $q->where('branch_id', $branchId))
            ->with([
                'cashier',
                'shift',
                'nextCashier',
                'originalCashier',
                'reassignedBy',
                'handover',
                'handoverStatus',
            ])
            ->orderBy('shift_date')
            ->paginate($perPage);
    }

    /**
     * Pending (not started) shifts, optionally filtered by cashier and branch.
     */
    public function getPendingShifts(?string $cashierId = null, ?string $branchId = null): Collection
    {
        $query = CashierShift::query()
            ->with(['cashier', 'shift', 'nextCashier'])
            ->where('status', ShiftStatus::NOT_STARTED)
            ->whereBetween('shift_date', [now(), now()->addMonth()])
            ->orderBy('shift_date')
            ->orderBy('actual_start_time');

        if ($cashierId) {
            $query->where('cashier_id', $cashierId);
        }

        if ($branchId) {
            $query->whereHas('shift', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        return $query->get();
    }

    /**
     * In-progress shifts (today), optionally filtered by cashier and branch.
     */
    public function getInProgressShifts(?string $cashierId = null, ?string $branchId = null): Collection
    {
        $query = CashierShift::query()
            ->with(['cashier', 'shift', 'nextCashier'])
            ->where('status', ShiftStatus::IN_PROGRESS)
            ->whereDate('shift_date', today())
            ->orderBy('actual_start_time');

        if ($cashierId) {
            $query->where('cashier_id', $cashierId);
        }

        if ($branchId) {
            $query->whereHas('shift', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        return $query->get();
    }

    /**
     * Completed shifts in optional date range, optionally filtered by cashier and branch.
     */
    public function getCompletedShifts(
        ?string $cashierId = null,
        ?string $branchId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null
    ): Collection {
        $query = CashierShift::query()
            ->with([
                'cashier',
                'shift',
                'nextCashier',
                'handoverStatus',
                'varianceDetails',
            ])
            ->where('status', ShiftStatus::COMPLETED)
            ->orderBy('shift_date', 'desc');

        if ($cashierId) {
            $query->where('cashier_id', $cashierId);
        }

        if ($branchId) {
            $query->whereHas('shift', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        if ($dateFrom) {
            $query->whereDate('shift_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('shift_date', '<=', $dateTo);
        }

        return $query->get();
    }

    /**
     * Reassigned shifts, optionally filtered by cashier and branch.
     */
    public function getReassignedShifts(?string $cashierId = null, ?string $branchId = null): Collection
    {
        $query = CashierShift::query()
            ->with([
                'cashier',
                'shift',
                'originalCashier',
                'reassignedBy',
                'nextCashier',
                'handoverStatus',
                'varianceDetails',
            ])
            ->where('status', ShiftStatus::REASSIGNED)
            ->orderBy('reassigned_at', 'desc');

        if ($cashierId) {
            $query->where(function ($q) use ($cashierId) {
                $q->where('cashier_id', $cashierId)
                    ->orWhere('original_cashier_id', $cashierId);
            });
        }

        if ($branchId) {
            $query->whereHas('shift', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        return $query->get();
    }

    /**
     * Create a new cashier shift.
     */
    public function create(array $data): CashierShift
    {
        return CashierShift::create($data);
    }

    /**
     * Update cashier shift.
     */
    public function update(CashierShift $shift, array $data): bool
    {
        return $shift->update($data);
    }

    /**
     * Delete cashier shift.
     */
    public function delete(CashierShift $shift): bool
    {
        return $shift->delete();
    }

    /**
     * Shifts for a cashier on a given date.
     */
    public function getShiftsByCashierAndDate(string $cashierId, Carbon $date): Collection
    {
        return CashierShift::where('cashier_id', $cashierId)
            ->whereDate('shift_date', $date)
            ->with(['shift', 'nextCashier'])
            ->orderBy('actual_start_time')
            ->get();
    }

    /**
     * Next not-started shift for cashier after given date.
     */
    public function getNextShift(string $cashierId, Carbon $afterDate): ?CashierShift
    {
        return CashierShift::where('cashier_id', $cashierId)
            ->where('status', ShiftStatus::NOT_STARTED)
            ->where('shift_date', '>', $afterDate)
            ->orderBy('shift_date')
            ->first();
    }

    /**
     * Whether the cashier has an overlapping not-started or in-progress shift on the date.
     */
    public function hasOverlappingShift(string $cashierId, string $shiftId, Carbon $date): bool
    {
        return CashierShift::where('cashier_id', $cashierId)
            ->where('shift_id', '!=', $shiftId)
            ->whereDate('shift_date', $date)
            ->whereIn('status', [ShiftStatus::NOT_STARTED, ShiftStatus::IN_PROGRESS])
            ->exists();
    }
}
