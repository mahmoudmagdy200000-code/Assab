<?php

namespace Modules\Shift\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shared start transition for the existing cashier and manager entry points. */
final class CashierShiftStartService
{
    public function __construct(
        private DatabaseManager $database,
        private CountedReassignmentGuard $reassignmentGuard
    ) {}

    public function startShift(CashierShift $expected): CashierShift
    {
        return $this->database->transaction(function () use ($expected) {
            $shift = CashierShift::withoutEagerLoads()->whereKey($expected->id)->lockForUpdate()->firstOrFail();
            if ($shift->cashier_id !== $expected->cashier_id
                || ! in_array($shift->status, [ShiftStatus::NOT_STARTED, ShiftStatus::REASSIGNED], true)) {
                throw new ConflictHttpException('SHIFT_NO_LONGER_PENDING');
            }
            $shift = app(ReassignmentReportOwnershipService::class)->separate($shift);
            if ($shift->history()->where('action', 'reassignment_work_assigned')->exists()
                && app(ShiftCashCountService::class)->currentFor($shift->id) !== null) {
                throw new ConflictHttpException('REPORT_ALREADY_SUBMITTED');
            }
            $shift->update(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()]);
            $shift->recordHistory('started', null, [
                'status' => ShiftStatus::IN_PROGRESS->value,
                'actual_start_time' => $shift->actual_start_time,
            ]);

            return $shift;
        });
    }
}
