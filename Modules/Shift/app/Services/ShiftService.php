<?php

namespace Modules\Shift\Services;

use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Repositories\CashierShiftRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ShiftService
{
    public function __construct(
        private CashierShiftRepositoryInterface $cashierShiftRepository
    ) {}

    /**
     * Resolve next cashier from the chronologically next shift (same day, same branch).
     * Shift order: Morning (06:00) → Afternoon (12:00) → Evening (18:00) → Night (00:00).
     * Next = the shift that starts when the current one ends (start_time >= end_time);
     * when current ends at midnight (00:00), next is Night (start 00:00).
     * Used for display; override only when handing over.
     */
    public function getNextShiftCashier(CashierShift $shift): ?Cashier
    {
        $shift->loadMissing('shift');

        if (!$shift->shift || !$shift->shift->branch_id) {
            return null;
        }

        $endTime = $shift->shift->end_time;
        $endTimeStr = $endTime instanceof \Carbon\Carbon
            ? $endTime->format('H:i:s')
            : (string) $endTime;
        $isMidnightEnd = $endTimeStr === '00:00:00';

        $query = CashierShift::where('cashier_shifts.id', '!=', $shift->id)
            ->where('cashier_shifts.shift_date', $shift->shift_date)
            ->join('shifts', 'cashier_shifts.shift_id', '=', 'shifts.id')
            ->where('shifts.branch_id', $shift->shift->branch_id)
            ->whereIn('cashier_shifts.status', [
                ShiftStatus::NOT_STARTED->value,
                ShiftStatus::IN_PROGRESS->value,
            ]);

        if ($isMidnightEnd) {
            $query->where('shifts.start_time', '00:00:00');
        } else {
            $query->where('shifts.start_time', '>=', $endTime);
        }

        $next = $query->orderBy('shifts.start_time')
            ->select('cashier_shifts.*')
            ->with('cashier:id,name,email,phone')
            ->first();

        return $next?->cashier;
    }

    /**
     * Next recipient for display: next cashier when there is a chronologically next shift,
     * otherwise branch manager (last shift of the day).
     *
     * @return Cashier|BranchManager|null
     */
    public function getNextRecipientForDisplay(CashierShift $shift): Cashier|BranchManager|null
    {
        $nextCashier = $this->getNextShiftCashier($shift);
        if ($nextCashier !== null) {
            return $nextCashier;
        }

        $shift->loadMissing('shift');
        if (!$shift->shift || !$shift->shift->branch_id) {
            return null;
        }

        return BranchManager::query()
            ->where('branch_id', $shift->shift->branch_id)
            ->active()
            ->select('id', 'name', 'email', 'phone')
            ->first();
    }

    public function getPendingShifts(string $cashierId = null): LengthAwarePaginator
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

    public function getInProgressShifts(string $cashierId = null): Collection
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
     * @param string|null $cashierId
     * @param array|null $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getCompletedShifts(string $cashierId = null, ?array $filters = []): LengthAwarePaginator
    {
        $query = CashierShift::completed()
            ->with([
                'cashier',
                'shift',
                'nextCashier',
                'handoverStatus.reviewedBy',
                'handover.handoverTo',
                'handover.approvedBy',
                'varianceDetails'
            ]);

        // ✅ فلتر حسب البرانش (shift و cashier)
        if (!empty($filters['branch_id'])) {
            $query->whereHas('shift', function ($q) use ($filters) {
                $q->where('branch_id', $filters['branch_id']);
            })
                ->whereHas('cashier', function ($q) use ($filters) {
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

    public function getReassignedShifts(string $cashierId = null): Collection
    {
        $query = CashierShift::reassigned()
            ->with([
                'cashier:id,name,branch_id',
                'shift:id,name,start_time,end_time,branch_id,is_active',
                'shift.branch:id,name,location',
                'originalCashier:id,name,branch_id',
                'reassignedBy:id,name,email,phone',
                'nextCashier:id,name,email,phone',
                'handoverStatus',
                'handover',
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

    public function getShiftDetails(string $shiftId): CashierShift
    {
        $shift = CashierShift::with([
            'cashier',
            'shift',
            'shift.branch',
            'nextCashier',
            'originalCashier',
            'reassignedBy',
            'assignedBy',
            'salesBreakdown.aggregator',
            'handoverStatus.reviewedBy',
            'handover.handoverTo',
            'varianceDetails.responsibleCashier',
            'varianceAlerts',
            'history'
        ])->find($shiftId);

        if (!$shift) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Shift not found");
        }

        return $shift;
    }

    public function getShiftProgress(string $shiftId): array
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
