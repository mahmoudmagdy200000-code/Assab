<?php

namespace Modules\Shift\Repositories;

use Modules\Shift\Models\CashierShift;
use Modules\Shift\Enums\ShiftStatus;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class CashierShiftRepository implements CashierShiftRepositoryInterface
{
    public function findById(int $id): ?CashierShift
    {
        return CashierShift::with([
            'cashier',
            'shift',
            'nextCashier',
            'salesBreakdown.aggregator',
            'handoverStatus',
            'varianceDetails'
        ])->find($id);
    }

    public function getPendingShifts(?int $cashierId = null, ?int $branchId = null): Collection
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

    public function getInProgressShifts(?int $cashierId = null, ?int $branchId = null): Collection
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

    public function getCompletedShifts(
        ?int $cashierId = null,
        ?int $branchId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null
    ): Collection {
        $query = CashierShift::query()
            ->with([
                'cashier',
                'shift',
                'nextCashier',
                'handoverStatus',
                'varianceDetails'
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

    public function getReassignedShifts(?int $cashierId = null, ?int $branchId = null): Collection
    {
        $query = CashierShift::query()
            ->with([
                'cashier',
                'shift',
                'originalCashier',
                'reassignedBy',
                'nextCashier',
                'handoverStatus',
                'varianceDetails'
            ])
            ->where('status', ShiftStatus::REASSIGNED)
            ->orderBy('reassigned_at', 'desc');

        if ($cashierId) {
            $query->where(function($q) use ($cashierId) {
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

    public function create(array $data): CashierShift
    {
        return CashierShift::create($data);
    }

    public function update(CashierShift $shift, array $data): bool
    {
        return $shift->update($data);
    }

    public function delete(CashierShift $shift): bool
    {
        return $shift->delete();
    }

    public function getShiftsByCashierAndDate(int $cashierId, Carbon $date): Collection
    {
        return CashierShift::where('cashier_id', $cashierId)
            ->whereDate('shift_date', $date)
            ->with(['shift', 'nextCashier'])
            ->orderBy('actual_start_time')
            ->get();
    }

    public function getNextShift(int $cashierId, Carbon $afterDate): ?CashierShift
    {
        return CashierShift::where('cashier_id', $cashierId)
            ->where('status', ShiftStatus::NOT_STARTED)
            ->where('shift_date', '>', $afterDate)
            ->orderBy('shift_date')
            ->first();
    }

    public function hasOverlappingShift(int $cashierId, int $shiftId, Carbon $date): bool
    {
        return CashierShift::where('cashier_id', $cashierId)
            ->where('shift_id', '!=', $shiftId)
            ->whereDate('shift_date', $date)
            ->whereIn('status', [ShiftStatus::NOT_STARTED, ShiftStatus::IN_PROGRESS])
            ->exists();
    }
}
