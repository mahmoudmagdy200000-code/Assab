<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Keep a started predecessor's report aggregate on its original row; work gets an empty row. */
final class ReassignmentReportOwnershipService
{
    public function separate(CashierShift $source): CashierShift
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Reassignment separation requires the owner transaction.');
        }
        if ($source->original_cashier_id === null || $source->original_cashier_id === $source->cashier_id
            || $this->ownsIncomingReport($source)) {
            return $source;
        }
        // Reassigning an unstarted, empty schedule has no predecessor report to protect.
        if ($source->actual_start_time === null
            && ! $source->reportAggregate()->exists()) {
            return $source;
        }

        $recipientId = $source->cashier_id;
        $approval = ShiftHandoverStatus::where('cashier_shift_id', $source->id)->lockForUpdate()->first();
        // Includes completed/cancelled rows: the existing unique schedule key must not be overwritten.
        if (CashierShift::where('cashier_id', $recipientId)->where('shift_id', $source->shift_id)
            ->whereDate('shift_date', $source->shift_date)->whereKeyNot($source->id)->lockForUpdate()->exists()) {
            throw new ConflictHttpException('INCOMING_REPORT_ALREADY_EXISTS');
        }

        $hasReport = app(ShiftCashCountService::class)->currentFor($source->id) !== null;
        // REASSIGNED already represents a submitted predecessor report awaiting its financial handover.
        // Do not emit COMPLETED/ShiftEndedEvent just because another cashier starts work.
        $source->update(['cashier_id' => $source->original_cashier_id,
            'status' => $hasReport ? ShiftStatus::REASSIGNED : ShiftStatus::IN_PROGRESS]);
        $incoming = CashierShift::create([
            'cashier_id' => $recipientId,
            'shift_id' => $source->shift_id,
            'shift_date' => $source->shift_date,
            'status' => ShiftStatus::REASSIGNED,
            'original_cashier_id' => $source->cashier_id,
            'reassigned_by' => $source->reassigned_by,
            'reassignment_reason' => $source->reassignment_reason,
            'reassigned_at' => $source->reassigned_at,
            'assigned_by' => $source->assigned_by,
        ]);
        if ($approval) {
            // Operational acceptance is separate from the source's financial approval/evidence.
            ShiftHandoverStatus::create(['cashier_shift_id' => $incoming->id]);
        }
        $link = ['source_cashier_shift_id' => $source->id, 'incoming_cashier_shift_id' => $incoming->id,
            'source_report_owner_cashier_id' => $source->cashier_id, 'incoming_report_owner_cashier_id' => $recipientId];
        $incoming->recordHistory('reassignment_report_separated', null, $link);
        $source->recordHistory('reassignment_work_assigned', null, $link);

        return $incoming;
    }

    public function ownsIncomingReport(CashierShift $shift): bool
    {
        foreach ($shift->history()->where('action', 'reassignment_report_separated')->get() as $history) {
            // Historical recordHistory encodes JSON before the model cast; accept both representations.
            $link = $history->new_value;
            if (is_string($link)) {
                $link = json_decode($link, true);
            }
            if (is_array($link) && ($link['incoming_cashier_shift_id'] ?? null) === $shift->id
                && ($link['incoming_report_owner_cashier_id'] ?? null) === $shift->cashier_id) {
                return true;
            }
        }

        return false;
    }
}
