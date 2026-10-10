<?php

namespace Modules\Shift\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\BranchManagerService;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shared correction/reopen boundary. Workdays precede cashier/report row locks. */
class ShiftReportMutationGuard
{
    public function lockEditable(CashierShift $source, Model $actor, array $additionalCashierIds = []): CashierShift
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Report mutation guard requires a transaction.');
        }
        $snapshot = CashierShift::withoutEagerLoads()->findOrFail($source->id);
        $branchId = $snapshot->shift()->value('branch_id');
        $this->assertActor($snapshot, $actor, $branchId);

        $receivingDays = DB::table('cashier_shift_handover_receipts as receipts')
            ->join('cashier_shift_handovers as requests', 'requests.id', '=', 'receipts.cashier_shift_handover_id')
            ->where('requests.cashier_shift_id', $snapshot->id)
            ->whereNotNull('receipts.receiving_branch_manager_shift_id')
            ->select('receipts.receiving_branch_manager_shift_id');
        $lockedDays = DB::table('shift_liability_daily_locks')->where('cashier_shift_id', $snapshot->id)
            ->select('branch_manager_shift_id');
        $days = BranchManagerShift::query()->where(function ($query) use ($snapshot, $branchId, $receivingDays, $lockedDays) {
            $query->where(function ($sameDate) use ($snapshot, $branchId) {
                $sameDate->where('branch_id', $branchId)->whereDate('shift_date', $snapshot->shift_date);
            })->orWhereIn('id', $receivingDays)->orWhereIn('id', $lockedDays);
        })->orderBy('id')->lockForUpdate()->get();

        $ids = array_values(array_unique(array_filter(array_merge([$snapshot->id], $additionalCashierIds))));
        sort($ids, SORT_STRING);
        $shift = null;
        foreach ($ids as $id) {
            $lockedShift = CashierShift::withoutEagerLoads()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ((string) $id === (string) $snapshot->id) {
                $shift = $lockedShift;
            }
        }
        if ($shift->shift_id !== $snapshot->shift_id
            || $shift->shift_date->toDateString() !== $snapshot->shift_date->toDateString()) {
            throw new ConflictHttpException('REPORT_SOURCE_CHANGED');
        }
        $this->assertActor($shift, $actor, $shift->shift()->value('branch_id'));
        $requests = DB::table('cashier_shift_handovers')->where('cashier_shift_id', $shift->id)
            ->orderBy('id')->lockForUpdate()->get(['id', 'daily_closed_at']);
        $locks = DB::table('shift_liability_daily_locks')->where(function ($query) use ($shift, $days) {
            $query->where('cashier_shift_id', $shift->id)->orWhereIn('branch_manager_shift_id', $days->modelKeys());
        })->whereNull('released_at')->whereNull('superseded_at')->orderBy('id')->lockForUpdate()->get(['id']);
        $admin = DB::table('asab_shifts')->where('legacy_shift_id', $shift->id)
            ->orderBy('id')->lockForUpdate()->get(['id', 'status']);
        $finalOperations = DB::table('asab_operations')->where('module_key', 'shifts')
            ->whereIn('source_id', array_merge([$shift->id], $admin->pluck('id')->all(), $days->modelKeys()))
            ->where('status', 'final-approved')->orderBy('id')->lockForUpdate()->get(['id']);
        if ($days->contains(fn ($day) => $day->daily_report_submitted || $day->daily_report_submitted_at !== null)
            || $requests->contains(fn ($request) => $request->daily_closed_at !== null)
            || $locks->isNotEmpty()
            || $admin->contains(fn ($projection) => in_array($projection->status, ['closed', 'pending_review'], true))
            || $finalOperations->isNotEmpty()) {
            throw new ConflictHttpException('REPORT_REOPEN_REQUIRED');
        }

        return $shift;
    }

    private function assertActor(CashierShift $shift, Model $actor, ?string $branchId): void
    {
        if ($actor instanceof Cashier
            && (string) $actor->id === (string) $shift->cashier_id
            && (string) $actor->branch_id === (string) $branchId) {
            return;
        }
        if ($actor instanceof BranchManager && (string) $actor->branch_id === (string) $branchId) {
            app(BranchManagerService::class)->assertAssignedActiveManager($branchId, (string) $actor->id);

            return;
        }
        throw new AccessDeniedHttpException('ONLY_REPORT_OWNER_OR_ASSIGNED_BRANCH_MANAGER');
    }
}
