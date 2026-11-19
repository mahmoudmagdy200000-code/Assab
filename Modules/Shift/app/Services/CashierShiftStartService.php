<?php

namespace Modules\Shift\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shared start transition for the existing cashier and manager entry points. */
final class CashierShiftStartService
{
    public function __construct(
        private DatabaseManager $database,
        private CountedReassignmentGuard $reassignmentGuard
    ) {}

    public function startShift(CashierShift $expected, array $auditContext = []): CashierShift
    {
        return $this->database->transaction(function () use ($expected, $auditContext) {
            // Lock workday rows before cashier rows to share lock order with daily report submission.
            $snapshot = CashierShift::withoutEagerLoads()->findOrFail($expected->id);
            $branchId = $snapshot->shift()->value('branch_id');
            $businessDate = $this->businessDate($snapshot);
            $workdays = $branchId === null
                ? collect()
                : BranchManagerShift::withoutEagerLoads()
                    ->where('branch_id', $branchId)
                    ->whereDate('shift_date', $businessDate)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            // All starters in a chain lock the same rows in UUID order before
            // locking their individual incoming row, avoiding B/C lock inversion.
            $linkedId = $this->predecessorId($snapshot);
            $linkedSnapshot = $linkedId === null ? null : CashierShift::withoutEagerLoads()->findOrFail($linkedId);
            $lockChainId = $snapshot->operational_chain_id ?? $linkedSnapshot?->operational_chain_id;
            if ($lockChainId !== null) {
                CashierShift::withoutEagerLoads()->where(function ($rows) use ($lockChainId, $snapshot) {
                    $rows->where('operational_chain_id', $lockChainId)->orWhere('id', $snapshot->id);
                })
                    ->orderBy('id')->lockForUpdate()->get();
            } else {
                CashierShift::withoutEagerLoads()->whereIn('id', array_filter([$snapshot->id, $linkedId]))
                    ->orderBy('id')->lockForUpdate()->get();
            }
            $shift = CashierShift::withoutEagerLoads()->whereKey($expected->id)->lockForUpdate()->firstOrFail();
            if ($shift->shift_id !== $snapshot->shift_id
                || $this->businessDate($shift) !== $businessDate
                || $shift->shift()->value('branch_id') !== $branchId) {
                throw new ConflictHttpException('REPORT_SOURCE_CHANGED');
            }
            if ($shift->operational_chain_id !== $snapshot->operational_chain_id) {
                throw new ConflictHttpException('OPERATIONAL_CHAIN_CHANGED');
            }
            if ($linkedSnapshot !== null
                && CashierShift::withoutEagerLoads()->whereKey($linkedId)->lockForUpdate()->firstOrFail()->operational_chain_id !== $linkedSnapshot->operational_chain_id) {
                throw new ConflictHttpException('OPERATIONAL_CHAIN_CHANGED');
            }
            // A reassigned row is still an offer to the replacement cashier.
            // Only the existing accept endpoint can move it to NOT_STARTED;
            // neither cashier nor manager start may accept it implicitly.
            if ($shift->status === ShiftStatus::REASSIGNED) {
                throw new ConflictHttpException('ASSIGNMENT_ACCEPTANCE_REQUIRED');
            }
            if ($shift->cashier_id !== $expected->cashier_id
                || $shift->operational_ended_at !== null
                || $shift->status !== ShiftStatus::NOT_STARTED) {
                throw new ConflictHttpException('SHIFT_NO_LONGER_PENDING');
            }
            if (! $this->isLegacySharedReassignment($shift) && $shift->actual_start_time !== null) {
                throw new ConflictHttpException('SHIFT_NO_LONGER_PENDING');
            }
            if ($shift->history()->where('action', 'reassignment_work_assigned')->exists()
                && app(ShiftCashCountService::class)->currentFor($shift->id) !== null) {
                throw new ConflictHttpException('REPORT_ALREADY_SUBMITTED');
            }
            // Reject submitted workdays before separating legacy reassignments or mutating chain, status, or history.
            $this->assertWorkdayOpen($workdays);
            $shift = app(ReassignmentReportOwnershipService::class)->separate($shift);
            if ($shift->actual_start_time !== null) {
                throw new ConflictHttpException('SHIFT_NO_LONGER_PENDING');
            }
            if ($shift->history()->where('action', 'reassignment_work_assigned')->exists()
                && app(ShiftCashCountService::class)->currentFor($shift->id) !== null) {
                throw new ConflictHttpException('REPORT_ALREADY_SUBMITTED');
            }
            $chainId = $shift->operational_chain_id ?? $lockChainId ?? (string) Str::uuid();
            $owners = CashierShift::withoutEagerLoads()->where('operational_chain_id', $chainId)
                ->currentOperational()->whereKeyNot($shift->id)->orderBy('id')->lockForUpdate()->get();
            if ($owners->count() > 1) {
                throw new ConflictHttpException('OPERATIONAL_CHAIN_CONFLICT');
            }
            $predecessorId = $this->predecessorId($shift);
            if ($predecessorId !== null) {
                $predecessor = CashierShift::withoutEagerLoads()->whereKey($predecessorId)->lockForUpdate()->firstOrFail();
                if ($predecessor->operational_ended_at !== null
                    || ($predecessor->operational_chain_id !== null && $predecessor->operational_chain_id !== $chainId)
                    || ($owners->isNotEmpty() && $owners->first()->id !== $predecessorId)) {
                    throw new ConflictHttpException('OPERATIONAL_PREDECESSOR_CHANGED');
                }
                // Safe history linkage upgrades an old separated row without
                // inferring a chain from a mutable cashier or schedule identity.
                if ($predecessor->operational_chain_id === null) {
                    $predecessor->update(['operational_chain_id' => $chainId]);
                }
                if ($predecessor->actual_start_time !== null) {
                    $owners = collect([$predecessor]);
                }
            }
            $startedAt = now();
            foreach ($owners as $owner) {
                $owner->update(['operational_ended_at' => $startedAt]);
                $owner->recordHistory('operational_responsibility_ended', null, [
                    'operational_ended_at' => $startedAt,
                    'incoming_cashier_shift_id' => $shift->id,
                    'operational_chain_id' => $chainId,
                ]);
            }
            $shift->update(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => $startedAt,
                'operational_chain_id' => $chainId]);
            $historyPayload = [
                'status' => ShiftStatus::IN_PROGRESS->value,
                'actual_start_time' => $shift->actual_start_time,
            ];
            if (! empty($auditContext['is_late_start'])) {
                $historyPayload['is_late_start'] = true;
                $historyPayload['scheduled_start_at'] = $auditContext['scheduled_start_at'] ?? null;
                $historyPayload['late_start_reason'] = $auditContext['late_start_reason'] ?? null;
                $historyPayload['authorized_by_id'] = $auditContext['authorized_by_id'] ?? null;
                $historyPayload['authorized_by_type'] = $auditContext['authorized_by_type'] ?? null;
            }
            $shift->recordHistory('started', null, $historyPayload, $auditContext['notes'] ?? null);

            return $shift;
        });
    }

    private function businessDate(CashierShift $shift): string
    {
        return $shift->shift_date instanceof CarbonInterface
            ? $shift->shift_date->toDateString()
            : substr((string) $shift->shift_date, 0, 10);
    }

    private function isLegacySharedReassignment(CashierShift $shift): bool
    {
        return $shift->original_cashier_id !== null
            && $shift->original_cashier_id !== $shift->cashier_id
            && $shift->actual_start_time !== null
            && $shift->history()->where('action', 'reassignment_report_separated')->doesntExist();
    }

    private function assertWorkdayOpen(Collection $workdays): void
    {
        if ($workdays->contains(fn (BranchManagerShift $day) => $day->daily_report_submitted || $day->daily_report_submitted_at !== null)) {
            throw new ConflictHttpException('REPORT_REOPEN_REQUIRED');
        }
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
}
