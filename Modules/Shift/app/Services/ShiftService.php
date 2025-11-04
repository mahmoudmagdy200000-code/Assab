<?php

namespace Modules\Shift\Services;

use Modules\Shift\Models\CashierShift;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Repositories\CashierShiftRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Shift\Models\Shift;

class ShiftService
{
    public function __construct(
        private CashierShiftRepositoryInterface $cashierShiftRepository
    ) {}

    public function getPendingShifts(int $cashierId = null): LengthAwarePaginator
    {
        $query = CashierShift::upcoming() // ✅ استخدم upcoming بدل pending
            ->with([
                'cashier',
                'shift',
                'nextCashier',
                'originalCashier',
                'reassignedBy'
            ])
            ->whereDate('shift_date', '>=', now()->subMonth()->toDateString())
            ->whereDate('shift_date', '<=', now()->addMonth()->toDateString())
            ->orderBy('shift_date')
            ->orderBy(
                Shift::select('start_time')
                    ->whereColumn('shifts.id', 'cashier_shifts.shift_id')
            );

        if ($cashierId) {
            $query->where('cashier_id', $cashierId);
        }

        return $query->paginate(10);
    }


    /**
     * Retrieve in-progress shifts and the next shift
     */
    // public function getInProgressAndNextShift(int $cashierId = null): array
    // {

    //     $inProgressShifts = CashierShift::inProgress()
    //         ->with(['cashier', 'shift'])
    //         ->orderBy('actual_start_time')
    //         ->when($cashierId, fn($q) => $q->where('cashier_id', $cashierId))
    //         ->get();


    //     $nextShift = CashierShift::where('status', 'not_started')
    //         ->whereDate('shift_date', today())
    //         ->join('shifts', 'cashier_shifts.shift_id', '=', 'shifts.id')
    //         ->when($cashierId, fn($q) => $q->where('cashier_id', $cashierId))
    //         ->orderBy('shifts.start_time')
    //         ->select('cashier_shifts.*')
    //         ->with(['cashier', 'shift'])
    //         ->first();

    //     return [
    //         'in_progress' => $inProgressShifts,
    //         'next_shift' => $nextShift,
    //     ];
    // }

    public function getInProgressShifts(int $cashierId = null): Collection
    {
        $query = CashierShift::inProgress()
            ->with(['cashier', 'shift', 'nextCashier'])
            ->orderBy('actual_start_time');

        if ($cashierId) {
            $query->where('cashier_id', $cashierId);
        }

        return $query->get();
    }

    /**
     * Get completed shifts with optional filtering
     *
     * @param int|null $cashierId
     * @param array|null $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getCompletedShifts(int $cashierId = null, ?array $filters = []): LengthAwarePaginator
    {
        $query = CashierShift::completed()
            ->with(['cashier', 'shift', 'nextCashier', 'handoverStatus', 'varianceDetails']);

        // ✅ فلتر حسب البرانش
        if (!empty($filters['branch_id'])) {
            $query->whereHas('shift', function ($q) use ($filters) {
                $q->where('branch_id', $filters['branch_id']);
            });
        }

        if ($cashierId) {
            $query->where('cashier_id', $cashierId);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('shift_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('shift_date', '<=', $filters['date_to']);
        }

        return $query->paginate(10);
    }

    public function getReassignedShifts(int $cashierId = null): Collection
    {
        $query = CashierShift::reassigned()
            ->with([
                'cashier',
                'shift',
                'originalCashier',
                'reassignedBy',
                'nextCashier',
                'handoverStatus',
                'varianceDetails'
            ]);

        if ($cashierId) {
            $query->where(function ($q) use ($cashierId) {
                $q->where('cashier_id', $cashierId)
                    ->orWhere('original_cashier_id', $cashierId);
            });
        }

        return $query->get();
    }

    public function getShiftDetails(int $shiftId): CashierShift
    {
        return CashierShift::with([
            'cashier',
            'shift',
            'nextCashier',
            'originalCashier',
            'reassignedBy',
            'salesBreakdown.aggregator',
            'handoverStatus.reviewedBy',
            'varianceDetails.responsibleCashier',
            'varianceAlerts',
            'history'
        ])->findOrFail($shiftId);
    }

    public function getShiftProgress(int $shiftId): array
    {
        $shift = CashierShift::findOrFail($shiftId);

        $totalMinutes = $shift->actual_start_time
            ? $shift->actual_start_time->diffInMinutes($shift->shift->end_time)
            : 0;

        $elapsedMinutes = $shift->actual_start_time
            ? $shift->actual_start_time->diffInMinutes(now())
            : 0;

        $progress = $totalMinutes > 0
            ? min(($elapsedMinutes / $totalMinutes) * 100, 100)
            : 0;

        return [
            'title' => $shift->shift->name,
            'description' => "Shift for {$shift->shift_date->format('d M Y')}",
            'status' => $shift->status->label(),
            'start_time' => $shift->shift->start_time,
            'end_time' => $shift->shift->end_time,
            'actual_start_time' => $shift->actual_start_time,
            'actual_end_time' => $shift->actual_end_time,
            'duration_minutes' => $totalMinutes,
            'elapsed_minutes' => $elapsedMinutes,
            'progress_percentage' => round($progress, 2),
        ];
    }
}
