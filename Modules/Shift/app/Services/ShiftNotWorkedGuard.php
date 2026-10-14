<?php

namespace Modules\Shift\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\BranchManagerService;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** OPS-04: no-work is an operational fact, never a zero financial report. */
final class ShiftNotWorkedGuard
{
    /** Revalidate assigned-manager authority before the existing replay middleware. */
    public function handle(\Illuminate\Http\Request $request, \Closure $next): mixed
    {
        try {
            $actor = $request->user();
            if (! $actor instanceof BranchManager) {
                throw new AccessDeniedHttpException('ONLY_ASSIGNED_ACTIVE_BRANCH_MANAGER');
            }
            $source = CashierShift::withoutEagerLoads()
                ->whereHas('shift', fn ($query) => $query->where('branch_id', $actor->branch_id))
                ->findOrFail($request->route('shift'));
            $this->assertActor($source, $actor);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return \Modules\Shift\Http\Controllers\HandoverErrorResponse::domain('SHIFT_NOT_FOUND', 404);
        } catch (AccessDeniedHttpException|ConflictHttpException $exception) {
            return \Modules\Shift\Http\Controllers\HandoverErrorResponse::from($exception, 'authorize mark not worked');
        }

        return $next($request);
    }

    public function canMark(CashierShift $shift, mixed $actor): bool
    {
        if (! $actor instanceof BranchManager) {
            return false;
        }
        try {
            $this->assertActor($shift, $actor);
            $this->assertEligible($shift);

            return true;
        } catch (AccessDeniedHttpException|ConflictHttpException $exception) {
            return false;
        }
    }

    public function assertActor(CashierShift $shift, BranchManager $actor): void
    {
        $branch = $shift->shift()->value('branch_id');
        if (! $actor->is_active || $actor->status !== 'active' || $actor->isSuspended()
            || ! $branch || (string) $actor->branch_id !== (string) $branch
            || (string) $shift->cashier()->value('branch_id') !== (string) $branch) {
            throw new AccessDeniedHttpException('ONLY_ASSIGNED_ACTIVE_BRANCH_MANAGER');
        }
        app(BranchManagerService::class)->assertAssignedActiveManager((string) $branch, (string) $actor->id);
    }

    public function assertEligible(CashierShift $shift): void
    {
        if ($shift->status !== ShiftStatus::NOT_STARTED || $shift->notWorkedHistory()->exists()) {
            throw new ConflictHttpException('SHIFT_NOT_WORKED_NOT_ELIGIBLE');
        }
        $this->assertNoEvidence($shift);
        // Never declare a dated schedule unused while another assignment owns it.
        if (CashierShift::withoutEagerLoads()->where('shift_id', $shift->shift_id)
            ->whereDate('shift_date', $shift->shift_date)->whereKeyNot($shift->id)
            ->where('status', '!=', ShiftStatus::CANCELED->value)->exists()) {
            throw new ConflictHttpException('SHIFT_NOT_WORKED_ASSIGNMENT_AMBIGUOUS');
        }
    }

    public function assertNoEvidence(CashierShift $shift): void
    {
        $this->assertReassignmentProvenance($shift);
        foreach (['actual_start_time', 'actual_end_time', 'operational_chain_id', 'operational_ended_at',
            'handed_over_at', 'pos_receipt', 'next_cashier_id', 'review_status', 'reviewed_at', 'review_reason', 'handover_notes'] as $field) {
            if ($shift->getRawOriginal($field) !== null) {
                throw new ConflictHttpException('SHIFT_NOT_WORKED_HAS_EVIDENCE');
            }
        }
        foreach (['opening_balance', 'closing_balance', 'expected_balance', 'variance', 'total_sales',
            'net_sales', 'vat_amount', 'cash_collected', 'card_payments'] as $field) {
            // Accept decimal zero representations; never round nonzero evidence away.
            if (! preg_match('/\A[+-]?0+(?:\.0+)?\z/', (string) $shift->getRawOriginal($field))) {
                throw new ConflictHttpException('SHIFT_NOT_WORKED_HAS_EVIDENCE');
            }
        }
        $id = $shift->id;
        $queries = [
            DB::table('shift_sales_breakdown')->where('cashier_shift_id', $id),
            DB::table('shift_handover_status')->where('cashier_shift_id', $id),
            DB::table('cashier_shift_handovers')->where('cashier_shift_id', $id),
            DB::table('branch_manager_cash_transfers')->where('destination_cashier_shift_id', $id),
            DB::table('cashier_shift_handover_receipts')->where('receiving_cashier_shift_id', $id),
            DB::table('shift_transfer_attempts')->where('receiving_cashier_shift_id', $id),
            DB::table('shift_transfer_rejection_evidence')->where('receiving_cashier_shift_id', $id),
            DB::table('shift_report_aggregates')->where('source_type', 'cashier_shift')->where('source_id', $id),
            DB::table('shift_report_cash_counts')->where('cashier_shift_id', $id),
            DB::table('shift_variance_details')->where('cashier_shift_id', $id),
            DB::table('shift_variance_alerts')->where('cashier_shift_id', $id),
            DB::table('shift_liability_allocations')->where('cashier_shift_id', $id),
            DB::table('shift_liability_postings')->where('cashier_shift_id', $id),
            DB::table('shift_liability_daily_locks')->where('cashier_shift_id', $id),
            DB::table('cashier_custody_transactions')->where('related_shift_id', $id),
            DB::table('personal_ledger_transactions')->where('related_shift_id', $id),
            DB::table('asab_shifts')->where('legacy_shift_id', $id),
            DB::table('asab_operations')->where('module_key', 'shifts')->where('source_id', $id),
            DB::table('cashier_shift_history')->where('cashier_shift_id', $id)->whereIn('action', [
                'started', 'ended', 'completed', 'ended_without_handover', 'handover_recorded',
                'reassigned_with_handover', 'reassignment_report_separated', 'reassignment_work_assigned',
                'operational_responsibility_ended', 'physical_return_recounted',
            ]),
            // Legacy requests have no destination shift FK: ambiguity is not evidence of absence.
            DB::table('cashier_shift_handovers as incoming')->join('cashier_shifts as source', 'source.id', '=', 'incoming.cashier_shift_id')
                ->join('shifts as slot', 'slot.id', '=', 'source.shift_id')
                ->where('incoming.handover_to_type', 'cashier')->where('incoming.handover_to_id', $shift->cashier_id)
                ->where('slot.branch_id', $shift->shift()->value('branch_id'))
                ->whereDate('source.shift_date', $shift->shift_date),
        ];
        foreach ($queries as $query) {
            if ($this->hasRow($query)) {
                throw new ConflictHttpException('SHIFT_NOT_WORKED_HAS_EVIDENCE');
            }
        }
    }

    /** F07-01: only a complete administrative reassignment/acceptance trail proves no work. */
    private function assertReassignmentProvenance(CashierShift $shift): void
    {
        $hasFields = false;
        foreach (['original_cashier_id', 'reassigned_by', 'reassigned_at', 'reassignment_reason'] as $field) {
            $hasFields = $hasFields || $shift->getRawOriginal($field) !== null;
        }
        $query = $shift->history()->whereIn('action', [
            'reassigned', 'reassigned_shift_accepted_without_handover',
            'reassigned_shift_accepted', 'shift_reassigned',
        ])->orderBy('created_at')->orderBy('id');
        $facts = (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->get();
        if (! $hasFields && $facts->isEmpty()) {
            return;
        }
        $conflict = static fn () => new ConflictHttpException('SHIFT_NOT_WORKED_REASSIGNMENT_AMBIGUOUS');
        if (! $shift->original_cashier_id || ! $shift->reassigned_at || $facts->isEmpty()) {
            throw $conflict();
        }
        $lastAssignment = null;
        $accepted = false;
        foreach ($facts as $fact) {
            $old = $this->historyValue($fact->old_value);
            $new = $this->historyValue($fact->new_value);
            if (! $fact->created_at || ! $fact->performed_by || ! $old || ! $new) {
                throw $conflict();
            }
            if ($fact->action === 'reassigned') {
                // Existing controller preserves the first original cashier even across multiple hops.
                if (($old['cashier_id'] ?? null) !== $shift->original_cashier_id
                    || ! isset($new['cashier_id']) || ! array_key_exists('reason', $new)
                    || ! in_array($fact->performed_by_type, ['branch_manager', 'cashier'], true)) {
                    throw $conflict();
                }
                $lastAssignment = $fact;
                $accepted = false;
            } elseif ($fact->action === 'reassigned_shift_accepted_without_handover' && $lastAssignment) {
                $assigned = $this->historyValue($lastAssignment->new_value)['cashier_id'];
                if ($accepted || ($old['status'] ?? null) !== 'reassigned'
                    || ($new['status'] ?? null) !== 'not_started'
                    || ($new['accepted_by_cashier_id'] ?? null) !== $assigned
                    // Legacy recordHistory maps the cashier morph alias to 'system'.
                    // The explicit nonzero cashier ID and acceptance payload must still match.
                    || ! in_array($fact->performed_by_type, ['cashier', 'system'], true)
                    || (string) $fact->performed_by !== (string) $assigned) {
                    throw $conflict();
                }
                $accepted = true;
            } else {
                // Handover acceptance/unknown legacy actions cannot prove an administrative-only path.
                throw $conflict();
            }
        }
        $last = $lastAssignment ? $this->historyValue($lastAssignment->new_value) : null;
        if (! $accepted || ($last['cashier_id'] ?? null) !== $shift->cashier_id
            || ($last['reason'] ?? null) !== $shift->reassignment_reason
            || ($lastAssignment->performed_by_type === 'branch_manager'
                ? (string) $shift->reassigned_by !== (string) $lastAssignment->performed_by
                : $shift->reassigned_by !== null)) {
            throw $conflict();
        }
        // Actual activity, report ownership separation and money are independently rejected below.
        // Do not compare DB-generated history clocks with application-generated reassigned_at.
    }

    private function historyValue(mixed $value): ?array
    {
        // The existing reassignment writers JSON-encode values before the model's array cast.
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : null;
    }

    /** Exclusion requires a consistent terminal row AND a complete explicit audit fact. */
    public function assertExcluded(CashierShift $shift): void
    {
        // Locking read in a transaction: a snapshot read could miss a fact committed while the caller waited on locks.
        $facts = DB::transactionLevel() > 0 ? $shift->notWorkedHistory()->lockForUpdate()->get() : $shift->notWorkedHistory()->get();
        $fact = $facts->first();
        $value = $fact?->new_value;
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if ($shift->status !== ShiftStatus::CANCELED || $facts->count() !== 1
            || $fact->performed_by_type !== 'branch_manager' || ! $fact->performed_by
            || ! $fact->created_at || trim($fact->notes ?? '') === ''
            || ! is_array($value) || ($value['outcome'] ?? null) !== 'not_worked'
            || ($value['reason'] ?? null) !== $fact->notes
            || ($value['recorded_at'] ?? null) !== $fact->created_at->toIso8601String()) {
            throw new ConflictHttpException('SHIFT_NOT_WORKED_EVIDENCE_CONFLICT');
        }
        try {
            $this->assertNoEvidence($shift);
        } catch (ConflictHttpException $exception) {
            throw new ConflictHttpException('SHIFT_NOT_WORKED_EVIDENCE_CONFLICT', $exception);
        }
    }

    public function assertMutable(CashierShift $shift): void
    {
        // Integration hardening (I6-01 follow-up): inside a transaction this must be a locking read. A plain
        // exists() is a consistent read against the REPEATABLE READ snapshot, so a mutation transaction that
        // began (e.g. under the idempotency wrapper) before a no-work mark committed would not see the fact
        // even though the locked shift re-read already blocked until that commit.
        if ($this->hasRow($shift->notWorkedHistory()->toBase())) {
            throw new ConflictHttpException('SHIFT_MARKED_NOT_WORKED');
        }
    }

    private function hasRow(Builder $query): bool
    {
        // Locking reads avoid a stale repeatable-read snapshot after a competing writer commits.
        return (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->first() !== null;
    }
}
