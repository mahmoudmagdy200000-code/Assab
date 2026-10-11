<?php

namespace Modules\Shift\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
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
            // All starters in a chain lock the same rows in UUID order before
            // locking their individual incoming row, avoiding B/C lock inversion.
            $snapshot = CashierShift::withoutEagerLoads()->findOrFail($expected->id);
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
            if ($shift->operational_chain_id !== $snapshot->operational_chain_id) {
                throw new ConflictHttpException('OPERATIONAL_CHAIN_CHANGED');
            }
            if ($linkedSnapshot !== null
                && CashierShift::withoutEagerLoads()->whereKey($linkedId)->lockForUpdate()->firstOrFail()->operational_chain_id !== $linkedSnapshot->operational_chain_id) {
                throw new ConflictHttpException('OPERATIONAL_CHAIN_CHANGED');
            }
            if ($shift->cashier_id !== $expected->cashier_id
                || $shift->operational_ended_at !== null
                || ! in_array($shift->status, [ShiftStatus::NOT_STARTED, ShiftStatus::REASSIGNED], true)) {
                throw new ConflictHttpException('SHIFT_NO_LONGER_PENDING');
            }
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
            $shift->recordHistory('started', null, [
                'status' => ShiftStatus::IN_PROGRESS->value,
                'actual_start_time' => $shift->actual_start_time,
            ]);

            return $shift;
        });
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
