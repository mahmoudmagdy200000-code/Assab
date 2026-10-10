<?php

namespace Modules\Shift\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Financial writers must not overwrite an unseparated legacy predecessor report. Never a start gate. */
final class CountedReassignmentGuard
{
    public function __construct(private ShiftCashCountService $cashCounts) {}

    public function assertCanContinue(CashierShift $shift, ?Model $actor = null): void
    {
        if ($actor instanceof Cashier && $actor->id !== $shift->cashier_id) {
            throw new AccessDeniedHttpException('ONLY_REPORT_OWNER');
        }
        if ($actor instanceof BranchManager && $actor->branch_id !== $shift->shift()->value('branch_id')) {
            throw new AccessDeniedHttpException('ONLY_REPORT_BRANCH_MANAGER');
        }
        $changedOwner = $shift->original_cashier_id !== null
            && $shift->original_cashier_id !== $shift->cashier_id;
        $independentReport = app(ReassignmentReportOwnershipService::class)->ownsIncomingReport($shift);
        if ($changedOwner && ! $independentReport
            && $shift->history()->where('action', 'reassigned_with_handover')->exists()
            && $this->cashCounts->currentFor($shift->id) !== null) {
            throw new ConflictHttpException('REASSIGNMENT_SPLIT_REQUIRED');
        }
    }
}
