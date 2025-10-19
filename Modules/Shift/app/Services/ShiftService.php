<?php

namespace Modules\Shift\Services;

use Modules\Shift\Models\CashierShift;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Repositories\CashierShiftRepositoryInterface;
use Illuminate\Support\Collection;
use Modules\Shift\Models\Shift;

class ShiftService
{
    public function __construct(
        private CashierShiftRepositoryInterface $cashierShiftRepository
    ) {}

    public function getPendingShifts(int $cashierId = null): Collection
    {
        $query = CashierShift::pending()
            ->with(['cashier', 'shift', 'nextCashier'])
            ->whereDate('shift_date', '>=', now()->toDateString())
            ->whereDate('shift_date', '<=', now()->addMonth()->toDateString())
            ->orderBy('shift_date')
            ->orderBy(
                Shift::select('start_time')
                    ->whereColumn('shifts.id', 'cashier_shifts.shift_id')
            );

        if ($cashierId) {
            $query->where('cashier_id', $cashierId);
        }

        return $query->get();
    }


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

    public function getCompletedShifts(int $cashierId = null, ?array $filters = []): Collection
    {
        $query = CashierShift::completed()
            ->with(['cashier', 'shift', 'nextCashier', 'handoverStatus', 'varianceDetails']);

        if ($cashierId) {
            $query->where('cashier_id', $cashierId);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('shift_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('shift_date', '<=', $filters['date_to']);
        }

        return $query->get();
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
