<?php

namespace Modules\Shift\Services;

use Carbon\Carbon;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;

class ShiftStartEligibilityService
{
    public const NORMAL_WINDOW_HOURS = 2;

    /**
     * Evaluate scheduled start windows for a shift without mutating any state.
     *
     * Approved contract:
     * - Normal window (on-time): [scheduledStart - 2h, scheduledStart + 2h] inclusive.
     * - Late-start recovery window: (scheduledStart + 2h, max(scheduledEnd + 2h, next midnight after shift_date)).
     * - Expired: >= lateClosesAt.
     */
    public function evaluateWindow(CashierShift $cashierShift, ?Carbon $now = null): array
    {
        $cashierShift->loadMissing('shift');
        $shift = $cashierShift->shift;
        $tz = config('app.timezone', 'Asia/Riyadh');
        $now = ($now ? $now->copy() : Carbon::now($tz))->timezone($tz);

        if (! $shift || ! $cashierShift->shift_date || ! $shift->start_time || ! $shift->end_time) {
            return [
                'can_start' => false,
                'can_start_normal' => false,
                'can_late_start' => false,
                'is_late_start' => false,
                'window_phase' => 'unavailable',
                'reason' => 'بيانات الوردية غير مكتملة',
                'scheduled_start_at' => null,
                'scheduled_end_at' => null,
                'window_opens_at' => null,
                'window_closes_at' => null,
                'normal_window_opens_at' => null,
                'normal_window_closes_at' => null,
                'late_window_closes_at' => null,
                'server_time' => $now->toIso8601String(),
            ];
        }

        $dateStr = $cashierShift->shift_date instanceof Carbon
            ? $cashierShift->shift_date->format('Y-m-d')
            : Carbon::parse((string) $cashierShift->shift_date, $tz)->format('Y-m-d');

        $startTimeStr = $shift->start_time instanceof Carbon
            ? $shift->start_time->format('H:i:s')
            : (string) $shift->start_time;

        $endTimeStr = $shift->end_time instanceof Carbon
            ? $shift->end_time->format('H:i:s')
            : (string) $shift->end_time;

        if (strlen($startTimeStr) === 5) {
            $startTimeStr .= ':00';
        }
        if (strlen($endTimeStr) === 5) {
            $endTimeStr .= ':00';
        }

        $scheduledStart = Carbon::parse($dateStr.' '.$startTimeStr, $tz)->setSecond(0);
        $scheduledEnd = Carbon::parse($dateStr.' '.$endTimeStr, $tz)->setSecond(0);

        if ($scheduledEnd->lte($scheduledStart)) {
            $scheduledEnd->addDay();
        }

        $normalOpensAt = $scheduledStart->copy()->subHours(self::NORMAL_WINDOW_HOURS);
        $normalClosesAt = $scheduledStart->copy()->addHours(self::NORMAL_WINDOW_HOURS);
        $businessDayCutoff = Carbon::parse($dateStr, $tz)->addDay()->startOfDay();
        $endPlusGrace = $scheduledEnd->copy()->addHours(self::NORMAL_WINDOW_HOURS);
        $lateClosesAt = $endPlusGrace->gt($businessDayCutoff) ? $endPlusGrace : $businessDayCutoff;
        if ($normalClosesAt->gt($lateClosesAt)) {
            $lateClosesAt = $normalClosesAt->copy();
        }

        $windowPhase = 'normal';
        $reason = null;

        if ($now->lt($normalOpensAt)) {
            $windowPhase = 'before_open';
            $opensAtFormatted = $normalOpensAt->format('H:i');
            $reason = "لا يمكن بدء الوردية قبل موعدها بأكثر من ساعتين (تفتح نافذة البدء الساعة {$opensAtFormatted})";
        } elseif ($now->gt($normalClosesAt)) {
            if ($now->lt($lateClosesAt)) {
                $windowPhase = 'late';
                $closesAtFormatted = $normalClosesAt->format('H:i');
                $reason = "تجاوزت الوردية نافذة البدء الطبيعية (أُغلقت في {$closesAtFormatted})؛ يتطلب البدء المتأخر موافقة مدير الفرع";
            } else {
                $windowPhase = 'expired';
                $lateClosedFormatted = $lateClosesAt->format('H:i');
                $reason = "انتهت نافذة بدء الوردية (أُغلقت في {$lateClosedFormatted})";
            }
        }

        return [
            'can_start' => $windowPhase === 'normal',
            'can_start_normal' => $windowPhase === 'normal',
            'can_late_start' => $windowPhase === 'late',
            'is_late_start' => $windowPhase === 'late',
            'window_phase' => $windowPhase,
            'reason' => $reason,
            'scheduled_start_at' => $scheduledStart->toIso8601String(),
            'scheduled_end_at' => $scheduledEnd->toIso8601String(),
            'window_opens_at' => $normalOpensAt->toIso8601String(),
            'window_closes_at' => $normalClosesAt->toIso8601String(),
            'normal_window_opens_at' => $normalOpensAt->toIso8601String(),
            'normal_window_closes_at' => $normalClosesAt->toIso8601String(),
            'late_window_closes_at' => $lateClosesAt->toIso8601String(),
            'server_time' => $now->toIso8601String(),
        ];
    }

    /**
     * Resolve the cashier's next startable shift ID without letting expired,
     * inactive-schedule, finalized-workday, or unaccepted shifts deadlock later valid shifts.
     */
    public function nextStartableShiftId(
        string $cashierId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?Carbon $now = null
    ): ?string {
        $candidates = CashierShift::query()
            ->with(['shift:id,branch_id,start_time,end_time,is_active'])
            ->where('cashier_id', $cashierId)
            ->whereIn('status', [ShiftStatus::NOT_STARTED, ShiftStatus::REASSIGNED])
            ->whereNull('actual_start_time')
            ->whereNull('operational_ended_at')
            ->pendingDateWindow($dateFrom, $dateTo)
            ->orderBy('shift_date', 'asc')
            ->orderByRaw('(SELECT start_time FROM shifts WHERE shifts.id = cashier_shifts.shift_id) asc')
            ->orderBy('cashier_shifts.id', 'asc')
            ->get();

        $firstBeforeOpenId = null;

        foreach ($candidates as $candidate) {
            if ($candidate->status !== ShiftStatus::NOT_STARTED) {
                continue;
            }
            if (! $this->isTemplateActive($candidate)) {
                continue;
            }
            if ($this->isWorkdayFinalized($candidate)) {
                continue;
            }
            if ($this->hasOperationalChainConflict($candidate)) {
                continue;
            }

            $window = $this->evaluateWindow($candidate, $now);
            if ($window['window_phase'] === 'normal') {
                return (string) $candidate->id;
            }
            if ($window['window_phase'] === 'before_open' && $firstBeforeOpenId === null) {
                $firstBeforeOpenId = (string) $candidate->id;
            }
        }

        return $firstBeforeOpenId;
    }

    /**
     * Single source of truth for start-shift eligibility across lists, details, and start mutations.
     */
    public function evaluateForActor(
        CashierShift $cashierShift,
        string $actorRole = 'cashier',
        ?string $nextStartableShiftId = null,
        bool $enforceTurnOrder = true,
        ?Carbon $now = null
    ): array {
        $cashierShift->loadMissing(['shift:id,branch_id,start_time,end_time,is_active', 'notWorkedHistory']);
        $window = $this->evaluateWindow($cashierShift, $now);
        $status = $cashierShift->status;
        $statusValue = $status instanceof ShiftStatus ? $status->value : (string) $status;
        $isCashierActor = $actorRole === 'cashier';

        $base = [
            'start_window' => $window,
            'window_phase' => $window['window_phase'],
            'can_start' => false,
            'can_start_normal' => false,
            'can_start_late' => false,
            'is_late_start' => $window['is_late_start'],
            'requires_manager_authorization' => $window['is_late_start'] && $isCashierActor,
            'is_next_shift' => false,
            'can_accept_assignment' => $statusValue === ShiftStatus::REASSIGNED->value,
            'start_disabled_reason' => null,
            'start_disabled_code' => null,
        ];

        if ($statusValue === ShiftStatus::CANCELED->value || $cashierShift->notWorkedOutcome() !== null) {
            $base['start_disabled_code'] = 'SHIFT_CANCELED_OR_NOT_WORKED';
            $base['start_disabled_reason'] = 'تم إلغاء الوردية أو تسجيل عدم العمل بها ولا يمكن بدؤها';

            return $base;
        }

        if ($statusValue === ShiftStatus::COMPLETED->value
            || $statusValue === ShiftStatus::IN_PROGRESS->value
            || $cashierShift->actual_start_time !== null
            || $cashierShift->operational_ended_at !== null) {
            $base['start_disabled_code'] = 'SHIFT_ALREADY_STARTED';
            $base['start_disabled_reason'] = 'الوردية بدأت بالفعل أو انتهت';

            return $base;
        }

        if ($statusValue === ShiftStatus::REASSIGNED->value) {
            $base['start_disabled_code'] = 'ASSIGNMENT_ACCEPTANCE_REQUIRED';
            $base['start_disabled_reason'] = 'يجب قبول إعادة إسناد الوردية قبل بدئها';

            return $base;
        }

        if ($statusValue !== ShiftStatus::NOT_STARTED->value) {
            $base['start_disabled_code'] = 'SHIFT_NOT_PENDING';
            $base['start_disabled_reason'] = 'الوردية ليست في حالة انتظار البدء';

            return $base;
        }

        if (! $this->isTemplateActive($cashierShift)) {
            $base['start_disabled_code'] = 'SCHEDULE_INACTIVE';
            $base['start_disabled_reason'] = 'تنتمي هذه الوردية إلى جدول ورديات محدث أو غير نشط';

            return $base;
        }

        if ($this->isWorkdayFinalized($cashierShift)) {
            $base['start_disabled_code'] = 'WORKDAY_FINALIZED';
            $base['start_disabled_reason'] = 'لا يمكن بدء الوردية لأن يوم العمل مقفل ومُعتمد';

            return $base;
        }

        if ($this->hasOperationalChainConflict($cashierShift)) {
            $base['start_disabled_code'] = 'OPERATIONAL_OWNER_ALREADY_ACTIVE';
            $base['start_disabled_reason'] = 'لا يمكن بدء الوردية لأن هناك وردية أخرى نشطة حالياً في نفس السلسلة';

            return $base;
        }

        $isNextShift = false;
        if ($isCashierActor) {
            $resolvedNextId = $nextStartableShiftId ?? $this->nextStartableShiftId((string) $cashierShift->cashier_id, now: $now);
            $isNextShift = $resolvedNextId !== null && (string) $cashierShift->id === (string) $resolvedNextId;
            $base['is_next_shift'] = $isNextShift;
        } else {
            $base['is_next_shift'] = true;
        }

        if ($window['window_phase'] === 'before_open') {
            $base['start_disabled_code'] = 'BEFORE_WINDOW';
            $base['start_disabled_reason'] = $window['reason'];

            return $base;
        }

        if ($window['window_phase'] === 'expired' || $window['window_phase'] === 'unavailable') {
            $base['start_disabled_code'] = 'SHIFT_START_WINDOW_EXPIRED';
            $base['start_disabled_reason'] = $window['reason'];

            return $base;
        }

        if ($window['window_phase'] === 'late') {
            $base['is_next_shift'] = ! $isCashierActor;
            if ($isCashierActor) {
                $base['start_disabled_code'] = 'LATE_START_REQUIRES_MANAGER';
                $base['start_disabled_reason'] = $window['reason'];

                return $base;
            }

            $base['can_start'] = true;
            $base['can_start_late'] = true;

            return $base;
        }

        // Normal window [scheduledStart - 2h, scheduledStart + 2h]
        if ($isCashierActor && $enforceTurnOrder && ! $isNextShift) {
            $base['start_disabled_code'] = 'PREVIOUS_ACTIVE_SHIFT_PENDING';
            $base['start_disabled_reason'] = 'يجب بدء الوردية السابقة المتاحة أولاً';

            return $base;
        }

        $base['can_start'] = true;
        $base['can_start_normal'] = true;

        return $base;
    }

    public function isWorkdayFinalized(CashierShift $cashierShift): bool
    {
        $branchId = $cashierShift->shift?->branch_id;
        $workdayDate = $cashierShift->shift_date instanceof Carbon
            ? $cashierShift->shift_date->toDateString()
            : ($cashierShift->shift_date ? Carbon::parse((string) $cashierShift->shift_date)->toDateString() : null);

        if (! $branchId || ! $workdayDate) {
            return false;
        }

        return BranchManagerShift::query()
            ->where('branch_id', $branchId)
            ->whereDate('shift_date', $workdayDate)
            ->where(function ($q) {
                $q->where('daily_report_submitted', true)
                    ->orWhereNotNull('daily_report_submitted_at');
            })
            ->exists();
    }

    public function hasOperationalChainConflict(CashierShift $cashierShift): bool
    {
        if (! $cashierShift->operational_chain_id) {
            return false;
        }

        $ownerIds = CashierShift::withoutEagerLoads()
            ->where('operational_chain_id', $cashierShift->operational_chain_id)
            ->currentOperational()
            ->where('id', '!=', $cashierShift->id)
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        if ($ownerIds->isEmpty()) {
            return false;
        }

        $predecessorId = $this->predecessorId($cashierShift);
        if ($predecessorId !== null && $ownerIds->count() === 1 && $ownerIds->first() === $predecessorId) {
            return false;
        }

        return true;
    }

    private function predecessorId(CashierShift $shift): ?string
    {
        foreach ($shift->history()->where('action', 'reassignment_report_separated')->get() as $history) {
            $link = $history->new_value;
            $link = is_string($link) ? json_decode($link, true) : $link;
            if (is_array($link) && isset($link['source_cashier_shift_id'])) {
                return (string) $link['source_cashier_shift_id'];
            }
        }

        return null;
    }

    private function isTemplateActive(CashierShift $cashierShift): bool
    {
        $cashierShift->loadMissing('shift');
        $shift = $cashierShift->shift;
        if (! $shift) {
            return false;
        }

        if (array_key_exists('is_active', $shift->getAttributes())) {
            return (bool) $shift->is_active;
        }

        return (bool) ($cashierShift->shift()->value('is_active') ?? true);
    }
}
