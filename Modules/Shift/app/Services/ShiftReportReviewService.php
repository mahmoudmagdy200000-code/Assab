<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** BRC-07: financial locks precede review operations; evidence coordinates are server derived. */
class ShiftReportReviewService
{
    /** Informational projection only; every command revalidates these coordinates under locks. */
    public function reportState(string $sourceId): ?array
    {
        $adminIds = DB::table('asab_shifts')->where('legacy_shift_id', $sourceId)->pluck('id');
        $operations = Operation::where('module_key', 'shifts')->whereNull('corrective_ref_id')
            ->where(fn ($query) => $query->whereIn('payload->shiftId', $adminIds)->orWhereIn('source_id', $adminIds)
                ->orWhere('payload->legacyShiftId', $sourceId))->get();
        if ($operations->count() !== 1) {
            return null;
        }
        $operation = $operations->first();
        $members = DB::table('shift_liability_daily_locks')->where('cashier_shift_id', $sourceId)
            ->whereNull('released_at')->whereNull('superseded_at')->orderBy('branch_manager_shift_id')->get();

        return ['operation_id' => $operation->id, 'public_id' => $operation->public_id, 'status' => $operation->status,
            'phase' => $operation->status === Operation::STATUS_FINAL ? 'final' : ($operation->payload['pkg6Review']['phase'] ?? 'accountant_review'),
            'return_cycle' => $operation->payload['pkg6Review']['return_cycle'] ?? null,
            'return_reason' => $operation->payload['pkg6Review']['return_reason'] ?? null,
            'memberships' => $members->map(fn ($member) => ['day_id' => $member->branch_manager_shift_id,
                'report_revision' => $member->report_revision, 'allocation_version' => $member->allocation_version])->all()];
    }

    public function authorizeReview(Operation $operation, \Modules\Admin\Models\AsabUser $actor, string $role): void
    {
        $sourceId = $this->cashierSource($operation);
        if ($sourceId === null && $this->managerSource($operation) === null) {
            return;
        }
        if ($actor->status !== 'active' || (string) $actor->company_id !== (string) $operation->company_id
            || ! $actor->hasAnyAsabRole([$role])) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('REPORT_REVIEW_ACTOR_REQUIRED');
        }
        if ($role === 'accountant' && $sourceId !== null) {
            app(ShiftReportCorrectionActorPolicy::class)->assertAllowed(CashierShift::withoutEagerLoads()->findOrFail($sourceId), $actor);
        }
    }

    public function headReturned(Operation $operation, \Modules\Admin\Models\AsabUser $actor, ?string $reason): void
    {
        $sourceId = $this->cashierSource($operation);
        if ($sourceId === null) {
            if ($this->managerSource($operation) !== null) throw new ConflictHttpException('RETURN_INDIVIDUAL_SHF_REQUIRED');
            return;
        }
        $this->authorizeReview($operation, $actor, 'head');
        $source = CashierShift::withoutEagerLoads()->findOrFail($sourceId);
        $coordinate = $this->returnMembership($source, $operation);
        $payload = $operation->payload;
        $payload['pkg6Review'] = array_merge($payload['pkg6Review'] ?? [], [
            'phase' => 'head_returned', 'return_cycle' => (string) \Illuminate\Support\Str::uuid(),
            'original_day_id' => $coordinate['day_id'], 'head_returned_at' => now()->toIso8601String(),
            'head_returned_by' => $actor->id, 'return_reason' => $reason,
        ]);
        $operation->update(['payload' => $payload]);
        $this->invalidateDaySnapshot(BranchManagerShift::findOrFail($coordinate['day_id']), $source, $actor, $reason ?? 'Head return');
    }

    public function returnToManager(Operation $operation, \Modules\Admin\Models\AsabUser $actor, string $reason): void
    {
        $sourceId = $this->cashierSource($operation);
        if ($sourceId === null) {
            if ($this->managerSource($operation) !== null) throw new ConflictHttpException('RETURN_INDIVIDUAL_SHF_REQUIRED');
            return;
        }
        $this->authorizeReview($operation, $actor, 'accountant');
        if ($operation->status !== Operation::STATUS_PENDING
            || ! in_array($operation->payload['pkg6Review']['phase'] ?? 'accountant_review', ['accountant_review', 'head_returned'], true)) {
            throw new ConflictHttpException('REPORT_RETURN_CYCLE_REQUIRED');
        }
        $source = CashierShift::withoutEagerLoads()->findOrFail($sourceId);
        $coordinate = $this->returnMembership($source, $operation);
        $manager = app(\Modules\BranchManagers\Services\BranchManagerService::class)->assignedActiveManager($operation->branch_id);
        $revision = app(ShiftReportRevisionService::class)->currentCashierRevision($source);
        $count = app(ShiftCashCountService::class)->currentFor($sourceId);
        if ($revision === null || $count === null) throw new ConflictHttpException('REPORT_CURRENT_EVIDENCE_REQUIRED');
        app(ShiftReportManagerReviewService::class)->requireFor($source, $revision, $count->variance_halalas, $reason);
        $payload = $operation->payload;
        $cycle = $payload['pkg6Review']['return_cycle'] ?? (string) \Illuminate\Support\Str::uuid();
        $payload['pkg6Review'] = array_merge($payload['pkg6Review'] ?? [], ['phase' => 'manager_returned',
            'return_cycle' => $cycle, 'original_day_id' => $coordinate['day_id'],
            'manager_returned_at' => now()->toIso8601String(), 'accountant_returned_by' => $actor->id,
            'assigned_manager_id' => $manager->id, 'return_reason' => $reason]);
        $operation->update(['payload' => $payload]);
        $this->invalidateDaySnapshot(BranchManagerShift::findOrFail($coordinate['day_id']), $source, $actor, $reason);
        app(ShiftReportCorrectionNotificationService::class)->enqueueReturn($revision, $source, $manager, $cycle, $reason);
    }

    public function managerCorrectionAllowed(CashierShift $source, \Illuminate\Database\Eloquent\Model $actor): bool
    {
        if (! $actor instanceof \Modules\BranchManagers\Models\BranchManager) {
            return false;
        }
        $operation = $this->memberOperation($source->id, required: false);
        if ($operation === null) return false;
        if (($operation->payload['pkg6Review']['phase'] ?? null) !== 'manager_returned') {
            return false;
        }
        app(ShiftReportCorrectionActorPolicy::class)->assertAllowed($source, $actor);
        $coordinate = $this->memberSnapshot($source->id, $operation, requireReady: false);
        if ($operation->status !== Operation::STATUS_REJECTED
            || ($operation->payload['pkg6Review']['original_day_id'] ?? null) !== $coordinate['day_id']) {
            throw new ConflictHttpException('REPORT_RETURN_CYCLE_REQUIRED');
        }

        return true;
    }

    public function resubmit(CashierShift $source, \Modules\BranchManagers\Models\BranchManager $manager,
        int $expectedRevision, ?int $expectedAllocationVersion, string $cycle, string $reason, string $commandId): Operation
    {
        app(ShiftReportCorrectionActorPolicy::class)->assertAllowed($source, $manager);

        return DB::transaction(function () use ($source, $manager, $expectedRevision, $expectedAllocationVersion, $cycle, $reason, $commandId) {
            $source = $this->lockCashier($source);
            $this->assertReportEditable($source);
            if (! $this->managerCorrectionAllowed($source, $manager)) {
                throw new ConflictHttpException('REPORT_RETURN_CYCLE_REQUIRED');
            }
            $operation = $this->memberOperation($source->id);
            if (($operation->payload['pkg6Review']['return_cycle'] ?? null) !== $cycle) {
                throw new ConflictHttpException('STALE_RETURN_CYCLE');
            }
            $revision = app(ShiftReportRevisionService::class)->currentCashierRevision($source);
            if ($revision?->revision_number !== $expectedRevision) {
                throw new ConflictHttpException('STALE_REPORT_REVISION');
            }
            app(ShiftReportRevisionService::class)->assertFreshCount($source);
            app(ShiftReportManagerReviewService::class)->approveCurrent($source, $manager, $expectedRevision,
                $expectedAllocationVersion, $reason, $commandId);
            $coordinate = $this->memberSnapshot($source->id, $operation);
            $payload = $operation->payload;
            $payload['pkg6Review'] = array_merge($payload['pkg6Review'], ['phase' => 'accountant_review',
                'resubmitted_at' => now()->toIso8601String(), 'resubmitted_by' => $manager->id,
                'resubmitted_snapshot' => $coordinate]);
            $operation->update(['status' => Operation::STATUS_PENDING, 'payload' => $payload,
                'reject_reason' => null, 'rejected_by_id' => null, 'rejected_at' => null,
                'approved_by_id' => null, 'approved_at' => null]);
            $operation->steps()->create(['stage_id' => 'submit', 'action' => 'أعاد مدير الفرع تقديم تقرير الوردية',
                'actor_user_id' => null, 'actor_label' => $manager->name, 'note' => $reason,
                'meta' => ['actor_type' => 'branch_manager', 'actor_id' => $manager->id,
                    'return_cycle' => $cycle, 'command_id' => $commandId, 'snapshot' => $coordinate], 'occurred_at' => now()]);
            $source->update(['review_status' => null, 'reviewed_at' => null, 'review_reason' => null]);

            return $operation->fresh();
        });
    }
    public function lockOperation(Operation $operation): Operation
    {
        $this->requireTransaction();
        $stub = Operation::query()->findOrFail($operation->id);
        $cashierId = $this->cashierSource($stub);
        $dayId = $this->managerSource($stub);
        if ($cashierId !== null || $dayId !== null) {
            $this->lockScope($cashierId === null ? [] : [$cashierId], $dayId === null ? [] : [$dayId]);
        }
        $locked = Operation::query()->whereKey($stub->id)->lockForUpdate()->firstOrFail();
        if ($this->cashierSource($locked, current: true) !== $cashierId || $this->managerSource($locked) !== $dayId
            || $locked->company_id !== $stub->company_id || $locked->branch_id !== $stub->branch_id) {
            throw new ConflictHttpException('REPORT_SOURCE_CHANGED');
        }

        return $locked;
    }

    public function lockCashier(CashierShift $source, array $additionalSourceIds = [], array $additionalDates = [], array $additionalOwners = []): CashierShift
    {
        $this->lockScope(array_merge([(string) $source->id], $additionalSourceIds), [], $additionalDates, $additionalOwners);

        return CashierShift::withoutEagerLoads()->whereKey($source->id)->lockForUpdate()->firstOrFail();
    }

    public function lockDay(BranchManagerShift $day): BranchManagerShift
    {
        $this->lockScope([], [(string) $day->id]);

        return BranchManagerShift::withoutEagerLoads()->whereKey($day->id)->lockForUpdate()->firstOrFail();
    }

    public function cashierSource(Operation $operation, bool $current = false): ?string
    {
        if ($operation->module_key !== 'shifts') {
            return null;
        }
        $adminId = $operation->payload['shiftId'] ?? $operation->source_id;
        $admin = $adminId ? AdminShift::query()->whereKey($adminId)->when($current, fn ($query) => $query->lockForUpdate())->first() : null;
        if ($admin === null || $admin->isBranchManagerShift() || $admin->legacy_shift_id === null) {
            if (! empty($operation->payload['legacyShiftId'])) {
                throw new ConflictHttpException('REPORT_SOURCE_MAPPING_REQUIRED');
            }

            return null; // Native dashboard reports retain their inherited approval contract.
        }
        if ((string) $admin->company_id !== (string) $operation->company_id
            || (string) $admin->branch_id !== (string) $operation->branch_id
            || (isset($operation->payload['legacyShiftId'])
                && (string) $operation->payload['legacyShiftId'] !== (string) $admin->legacy_shift_id)) {
            throw new ConflictHttpException('REPORT_SOURCE_MAPPING_REQUIRED');
        }

        return (string) $admin->legacy_shift_id;
    }

    public function managerSource(Operation $operation): ?string
    {
        return $operation->module_key === 'sales' && $operation->origin === 'mobile'
            && ! empty($operation->payload['managerShiftId'])
            ? (string) $operation->payload['managerShiftId'] : null;
    }

    public function assertMutableCashier(CashierShift $source): void
    {
        $adminIds = DB::table('asab_shifts')->where('legacy_shift_id', $source->id)->orderBy('id')->lockForUpdate()->pluck('id');
        $final = DB::table('asab_operations')->where('module_key', 'shifts')->where('status', Operation::STATUS_FINAL)
            ->where(function ($query) use ($source, $adminIds) {
                $query->whereIn('source_id', $adminIds)->orWhereIn('payload->shiftId', $adminIds)
                    ->orWhere('payload->legacyShiftId', $source->id);
            })->orderBy('id')->lockForUpdate()->get()->isNotEmpty();
        $dayIds = DB::table('shift_liability_daily_locks')->where('cashier_shift_id', $source->id)
            ->whereNull('released_at')->whereNull('superseded_at')->orderBy('id')->lockForUpdate()->pluck('branch_manager_shift_id');
        $finalDay = DB::table('asab_operations')->where('module_key', 'sales')->where('origin', 'mobile')
            ->where('status', Operation::STATUS_FINAL)->whereIn('payload->managerShiftId', $dayIds)->orderBy('id')->lockForUpdate()->get()->isNotEmpty();
        if ($final || $finalDay) {
            throw new ConflictHttpException('REPORT_FINAL_APPROVED');
        }
    }

    public function assertMutableDay(BranchManagerShift $day): void
    {
        if (Operation::where('module_key', 'sales')->where('origin', 'mobile')->where('payload->managerShiftId', $day->id)
            ->where('status', Operation::STATUS_FINAL)->orderBy('id')->lockForUpdate()->get()->isNotEmpty()) {
            throw new ConflictHttpException('REPORT_FINAL_APPROVED');
        }
    }

    private function returnMembership(CashierShift $source, Operation $operation): array
    {
        if ($this->memberOperation($source->id)->id !== $operation->id) throw new ConflictHttpException('REPORT_OPERATION_MAPPING_REQUIRED');
        $members = DB::table('shift_liability_daily_locks')->where('cashier_shift_id', $source->id)
            ->whereNull('released_at')->whereNull('superseded_at')->orderBy('id')->lockForUpdate()->get();
        if ($members->count() !== 1) throw new ConflictHttpException('REPORT_WORKDAY_MEMBERSHIP_REQUIRED');
        $day = BranchManagerShift::withoutEagerLoads()->whereKey($members->first()->branch_manager_shift_id)->lockForUpdate()->firstOrFail();
        if (! $day->daily_report_submitted || (string) $day->branch_id !== (string) $operation->branch_id) {
            throw new ConflictHttpException('DAY_REPORT_NOT_SUBMITTED');
        }
        $this->assertMutableDay($day);
        return ['day_id' => (string) $day->id];
    }

    public function assertReportEditable(CashierShift $source): void
    {
        $this->assertMutableCashier($source);
        $adminIds = DB::table('asab_shifts')->where('legacy_shift_id', $source->id)->pluck('id');
        $operations = Operation::query()->where('module_key', 'shifts')
            ->where(fn ($query) => $query->whereIn('source_id', $adminIds)->orWhereIn('payload->shiftId', $adminIds)
                ->orWhere('payload->legacyShiftId', $source->id))->orderBy('id')->lockForUpdate()->get();
        if ($operations->contains(fn ($operation) => $operation->status === Operation::STATUS_APPROVED)) {
            throw new ConflictHttpException('REPORT_IN_HEAD_REVIEW');
        }
        if ($operations->contains(fn ($operation) => ($operation->payload['pkg6Review']['phase'] ?? '') === 'head_returned')) {
            throw new ConflictHttpException('REPORT_RETURN_CYCLE_REQUIRED');
        }
    }

    public function assertMutableOperation(Operation $operation): void
    {
        $sourceId = $this->cashierSource($operation, current: true);
        if ($sourceId !== null) {
            $this->assertMutableCashier(CashierShift::withoutEagerLoads()->whereKey($sourceId)->lockForUpdate()->firstOrFail());
        }
        $dayId = $this->managerSource($operation);
        if ($dayId !== null && DB::table('asab_operations')->where('module_key', 'sales')->where('origin', 'mobile')
            ->where('payload->managerShiftId', $dayId)->where('status', Operation::STATUS_FINAL)
            ->orderBy('id')->lockForUpdate()->get()->isNotEmpty()) {
            throw new ConflictHttpException('REPORT_FINAL_APPROVED');
        }
    }

    /** The submitted-day exception belongs exclusively to the authorized accountant's report command. */
    public function accountantCorrectionAllowed(CashierShift $source): bool
    {
        $this->assertReportEditable($source);
        $ids = DB::table('asab_shifts')->where('legacy_shift_id', $source->id)->pluck('id');
        $operations = Operation::query()->where('module_key', 'shifts')->whereNull('corrective_ref_id')
            ->where(fn ($query) => $query->whereIn('payload->shiftId', $ids)->orWhereIn('source_id', $ids)
                ->orWhere('payload->legacyShiftId', $source->id))->orderBy('id')->lockForUpdate()->get();
        if ($operations->isEmpty()) {
            return false;
        }
        if ($operations->count() !== 1) {
            throw new ConflictHttpException('REPORT_OPERATION_MAPPING_REQUIRED');
        }
        $operation = $operations->first();
        if ($operation->status !== Operation::STATUS_PENDING
            || ! in_array($operation->payload['pkg6Review']['phase'] ?? 'accountant_review', ['accountant_review'], true)) {
            throw new ConflictHttpException('REPORT_RETURN_CYCLE_REQUIRED');
        }
        $locks = DB::table('shift_liability_daily_locks')->where('cashier_shift_id', $source->id)
            ->whereNull('released_at')->whereNull('superseded_at')->orderBy('id')->lockForUpdate()->get();
        if ($locks->isEmpty()) {
            return false;
        }
        if ($locks->count() !== 1) {
            throw new ConflictHttpException('REPORT_WORKDAY_MEMBERSHIP_REQUIRED');
        }
        $day = BranchManagerShift::withoutEagerLoads()->whereKey($locks->first()->branch_manager_shift_id)->lockForUpdate()->firstOrFail();
        $admin = AdminShift::query()->whereKey($operation->payload['shiftId'] ?? $operation->source_id)->lockForUpdate()->firstOrFail();
        if (! $day->daily_report_submitted || $admin->status !== 'pending_review'
            || (string) $day->branch_id !== (string) $operation->branch_id) {
            throw new ConflictHttpException('REPORT_OPERATION_MAPPING_REQUIRED');
        }
        app(ShiftReportRevisionService::class)->assertFreshCount($source);
        if (app(ShiftCashCountService::class)->currentFor((string) $source->id) === null) {
            throw new ConflictHttpException('REPORT_CURRENT_EVIDENCE_REQUIRED');
        }

        return true;
    }

    /** Keep membership continuously reserved while replacing only this report's evidence. */
    public function syncCorrection(CashierShift $source, \Modules\Shift\Models\ShiftReportRevision $revision,
        \Illuminate\Database\Eloquent\Model $actor, string $reason, array $oldFinancial): void
    {
        $count = app(ShiftCashCountService::class)->currentFor((string) $source->id);
        if ($count === null) {
            return;
        }
        $adminRows = AdminShift::query()->where('legacy_shift_id', $source->id)->where('role', 'cashier')->get();
        foreach ($adminRows as $admin) {
            if ($admin->status === 'closed') {
                throw new ConflictHttpException('REPORT_FINAL_APPROVED');
            }
            $admin->update(['sales_amount' => $count->gross_halalas, 'cash_actual' => $count->counted_halalas,
                'cash_expected' => $count->expected_halalas, 'variance' => $count->variance_halalas,
                'cash_count_state' => 'counted', 'pending_incoming_counted' => $count->pending_incoming_counted_halalas]);
            $operations = Operation::query()->where('module_key', 'shifts')->whereNull('corrective_ref_id')
                ->where(fn ($query) => $query->where('payload->shiftId', $admin->id)->orWhere('source_id', $admin->id))->get();
            foreach ($operations as $operation) {
                $payload = $operation->payload ?? [];
                $payload = array_merge($payload, ['salesHalalas' => $count->gross_halalas, 'cardTotalHalalas' => $count->cards_halalas,
                    'aggregatorTotalsHalalas' => $count->apps_halalas, 'cashActualHalalas' => $count->counted_halalas,
                    'cashExpectedHalalas' => $count->expected_halalas, 'varianceHalalas' => $count->variance_halalas,
                    'pendingIncomingCountedHalalas' => $count->pending_incoming_counted_halalas]);
                $payload['pkg6Review'] = array_merge($payload['pkg6Review'] ?? [], ['revision_id' => $revision->id, 'count_id' => $count->id]);
                $payload['aggregatorBreakdown'] = \Modules\Shift\Models\ShiftSalesBreakdown::where('cashier_shift_id', $source->id)->orderBy('aggregator_id')->get()->map(fn ($row) => ['aggregator' => $row->aggregator_id, 'amountHalalas' => \App\Support\ShiftFinancialCalculator::storedSarToHalalas($row->amount)])->all();
                $operation->update(['payload' => $payload]);
            }
        }
        $locks = \Modules\Shift\Models\ShiftLiabilityDailyLock::active()->where('cashier_shift_id', $source->id)->orderBy('id')->get();
        $allocation = DB::table('shift_liability_allocations')->where('cashier_shift_id', $source->id)->whereNull('superseded_at')->first();
        foreach ($locks as $lock) {
            $lock->update(['superseded_at' => now(), 'reopened_at' => now(), 'reopened_by_type' => $actor->getMorphClass(),
                'reopened_by_id' => $actor->getKey(), 'reopen_reason' => $reason]);
            \Modules\Shift\Models\ShiftLiabilityDailyLock::create(['cashier_shift_id' => $source->id,
                'branch_manager_shift_id' => $lock->branch_manager_shift_id, 'report_revision' => $count->evidence_revision_id,
                'allocation_version' => $allocation?->version, 'locked_by_id' => $lock->locked_by_id, 'locked_at' => now()]);
            $day = BranchManagerShift::withoutEagerLoads()->whereKey($lock->branch_manager_shift_id)->firstOrFail();
            $dayRevision = app(ShiftReportRevisionService::class)->currentManagerRevision($day);
            if ($dayRevision !== null) {
                app(ShiftReportRevisionSnapshotService::class)->createSnapshotIfMissing($dayRevision, $day);
            }
            $updated = [];
            foreach (['cash_collected', 'card_payments'] as $field) {
                $updated[$field] = number_format((\App\Support\ShiftFinancialCalculator::storedSarToHalalas($day->$field ?? '0.00')
                    + \App\Support\ShiftFinancialCalculator::storedSarToHalalas($source->$field ?? '0.00')
                    - (int) ($oldFinancial[$field] ?? 0)) / 100, 2, '.', '');
            }
            $updated['aggregator_payments'] = number_format((\App\Support\ShiftFinancialCalculator::storedSarToHalalas($day->aggregator_payments ?? '0.00')
                + (int) $count->apps_halalas - (int) ($oldFinancial['aggregators'] ?? 0)) / 100, 2, '.', '');
            // Gross sales and the manager's physical handover/closing balance are separate immutable facts here.
            if (isset($oldFinancial['total_sales'])) {
                foreach (['total_sales', 'net_sales', 'vat_amount'] as $field) {
                    $updated[$field] = number_format((\App\Support\ShiftFinancialCalculator::storedSarToHalalas($day->$field ?? '0.00')
                        + \App\Support\ShiftFinancialCalculator::storedSarToHalalas($source->$field ?? '0.00')
                        - (int) ($oldFinancial[$field] ?? 0)) / 100, 2, '.', '');
                }
            }
            $day->update($updated);
            $nextDayRevision = app(ShiftReportRevisionService::class)->recordManagerRevision($day, $actor instanceof \Modules\BranchManagers\Models\BranchManager ? 'branch_manager' : 'accountant', (string) $actor->getKey(), $dayRevision?->revision_number);
            app(ShiftReportRevisionSnapshotService::class)->createSnapshotIfMissing($nextDayRevision, $day);
            $this->invalidateDaySnapshot($day, $source, $actor, $reason);
        }
    }

    public function invalidateDaySnapshot(BranchManagerShift $day, CashierShift $source, \Illuminate\Database\Eloquent\Model $actor, string $reason): void
    {
        $operations = Operation::query()->where('module_key', 'sales')->where('origin', 'mobile')->where('payload->managerShiftId', $day->id)->get();
        foreach ($operations as $operation) {
            if ($operation->status === Operation::STATUS_FINAL) {
                throw new ConflictHttpException('REPORT_FINAL_APPROVED');
            }
            $payload = $operation->payload ?? [];
            foreach (['total_sales' => 'totalSalesHalalas', 'cash_collected' => 'cashHalalas', 'card_payments' => 'cardHalalas',
                'aggregator_payments' => 'appsHalalas', 'variance' => 'varianceHalalas'] as $field => $key) {
                $payload[$key] = \App\Support\ShiftFinancialCalculator::storedSarToHalalas($day->$field ?? '0.00');
            }
            $payload['totalHalalas'] = $payload['totalSalesHalalas'];
            $payload['pkg6Review']['stale_members'] = $this->sorted(array_merge($payload['pkg6Review']['stale_members'] ?? [], [(string) $source->id]));
            $attributes = ['payload' => $payload];
            if ($operation->status === Operation::STATUS_APPROVED) {
                $attributes += ['status' => Operation::STATUS_PENDING, 'approved_at' => null, 'approved_by_id' => null];
            }
            $operation->update($attributes);
            $operation->steps()->create(['stage_id' => 'review', 'action' => 'تجديد دليل تقرير عضو اليوم',
                'actor_user_id' => $actor instanceof \Modules\Admin\Models\AsabUser ? $actor->id : null,
                'actor_label' => $actor->name, 'note' => $reason, 'meta' => ['member_source_id' => $source->id,
                    'actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->getKey(), 'day_snapshot_invalidated' => true], 'occurred_at' => now()]);
        }
    }

    /** Captured at accountant approval; compared again at head approval under the same root locks. */
    public function approvalSnapshot(Operation $operation, bool $final = false): ?array
    {
        $this->requireTransaction();
        $sourceId = $this->cashierSource($operation);
        $dayId = $this->managerSource($operation);
        if ($sourceId === null && $dayId === null) {
            return null;
        }
        $this->assertMutableOperation($operation);
        if ($sourceId !== null) {
            $source = CashierShift::withoutEagerLoads()->whereKey($sourceId)->lockForUpdate()->firstOrFail();
            $this->assertMutableCashier($source);
            // Preserve the inherited BRC-04 domain fence/code before the new snapshot gate.
            $admin = AdminShift::whereKey($operation->payload['shiftId'] ?? $operation->source_id)->lockForUpdate()->firstOrFail();
            if ($final && (int) $admin->variance < 0
                && ! DB::table('shift_liability_allocations')->where('cashier_shift_id', $sourceId)
                    ->whereNull('superseded_at')->where('manager_approval_status', 'approved')->orderBy('id')->lockForUpdate()->first()) {
                throw new \Modules\Admin\Exceptions\AsabException('BRANCH_LIABILITY_APPROVAL_PENDING',
                    'The shortage requires a current approved branch liability allocation', 'يلزم توزيع عجز حالي معتمد', 409);
            }
            $phase = $operation->payload['pkg6Review']['phase'] ?? 'accountant_review';
            if (in_array($phase, ['head_returned', 'manager_returned'], true)) {
                throw new ConflictHttpException('REPORT_RETURN_CYCLE_REQUIRED');
            }
            $snapshot = $this->memberSnapshot($sourceId, $operation);
        } else {
            $day = BranchManagerShift::withoutEagerLoads()->whereKey($dayId)->lockForUpdate()->firstOrFail();
            if ((string) $day->branch_id !== (string) $operation->branch_id
                || ! $day->daily_report_submitted) {
                throw new ConflictHttpException('DAY_REPORT_NOT_SUBMITTED');
            }
            $members = DB::table('shift_liability_daily_locks')->where('branch_manager_shift_id', $dayId)
                ->whereNull('released_at')->whereNull('superseded_at')->orderBy('cashier_shift_id')->lockForUpdate()->pluck('cashier_shift_id');
            if ($members->isEmpty()) {
                throw new ConflictHttpException('DAY_MEMBER_EVIDENCE_REQUIRED');
            }
            $snapshot = ['day_id' => $dayId, 'members' => []];
            $financial = [];
            foreach (['total_sales' => 'totalSalesHalalas', 'cash_collected' => 'cashHalalas', 'card_payments' => 'cardHalalas',
                'aggregator_payments' => 'appsHalalas', 'variance' => 'varianceHalalas'] as $field => $payloadKey) {
                $financial[$payloadKey] = \App\Support\ShiftFinancialCalculator::storedSarToHalalas($day->$field ?? '0.00');
                if (! array_key_exists($payloadKey, $operation->payload ?? [])
                    || (int) $operation->payload[$payloadKey] !== $financial[$payloadKey]) {
                    throw new ConflictHttpException('STALE_DAY_REPORT_SNAPSHOT');
                }
            }
            $snapshot['financial'] = $financial;
            foreach ($members->unique() as $memberId) {
                $memberOperation = $this->memberOperation((string) $memberId);
                $coordinate = $this->memberSnapshot((string) $memberId, $memberOperation);
                $requiredStatuses = $final ? [Operation::STATUS_FINAL] : [Operation::STATUS_APPROVED, Operation::STATUS_FINAL];
                if ($coordinate['day_id'] !== $dayId || ! in_array($memberOperation->status, $requiredStatuses, true)
                    || in_array($memberOperation->payload['pkg6Review']['phase'] ?? '', ['head_returned', 'manager_returned'], true)
                    || ($memberOperation->payload['pkg6Review']['approved_snapshot'] ?? null) !== $coordinate) {
                    throw new ConflictHttpException('DAY_MEMBER_NOT_FINAL_CURRENT');
                }
                $snapshot['members'][(string) $memberId] = $coordinate;
            }
            $snapshot['day_revision'] = app(ShiftReportRevisionService::class)->currentManagerRevision($day)?->id;
            if ($snapshot['day_revision'] === null) {
                throw new ConflictHttpException('DAY_REPORT_EVIDENCE_REQUIRED');
            }
        }
        if ($final && ($operation->payload['pkg6Review']['approved_snapshot'] ?? null) !== $snapshot) {
            throw new ConflictHttpException('STALE_APPROVED_REPORT');
        }

        return $snapshot;
    }

    private function memberSnapshot(string $sourceId, Operation $operation, bool $requireReady = true): array
    {
        $source = CashierShift::withoutEagerLoads()->whereKey($sourceId)->lockForUpdate()->firstOrFail();
        $branch = DB::table('shifts')->join('branches', 'branches.id', '=', 'shifts.branch_id')
            ->where('shifts.id', $source->shift_id)->first(['branches.id', 'branches.asab_company_id']);
        if (! $branch || (string) $branch->id !== (string) $operation->branch_id
            || (string) $branch->asab_company_id !== (string) $operation->company_id
            || $this->memberOperation($sourceId)->id !== $operation->id) {
            throw new ConflictHttpException('REPORT_SOURCE_MAPPING_REQUIRED');
        }
        $locks = DB::table('shift_liability_daily_locks')->where('cashier_shift_id', $sourceId)
            ->whereNull('released_at')->whereNull('superseded_at')->orderBy('id')->lockForUpdate()->get();
        if ($locks->count() !== 1) {
            throw new ConflictHttpException('REPORT_WORKDAY_MEMBERSHIP_REQUIRED');
        }
        $membership = $locks->first();
        $day = BranchManagerShift::withoutEagerLoads()->whereKey($membership->branch_manager_shift_id)->lockForUpdate()->firstOrFail();
        if (! $day->daily_report_submitted || (string) $day->branch_id !== (string) $branch->id) {
            throw new ConflictHttpException('DAY_REPORT_NOT_SUBMITTED');
        }
        app(ShiftReportRevisionService::class)->assertFreshCount($source);
        $revision = app(ShiftReportRevisionService::class)->currentCashierRevision($source);
        $count = app(ShiftCashCountService::class)->currentFor($sourceId);
        if (! $revision || ! $count || ! in_array($source->getRawOriginal('status'), ['completed', 'reassigned'], true)) {
            throw new ConflictHttpException('REPORT_CURRENT_EVIDENCE_REQUIRED');
        }
        foreach (['total_sales' => 'gross_halalas', 'card_payments' => 'cards_halalas'] as $field => $countField) {
            if (\App\Support\ShiftFinancialCalculator::storedSarToHalalas($source->$field ?? '0.00') !== (int) $count->$countField) {
                throw new ConflictHttpException('STALE_REPORT_COUNT_EVIDENCE');
            }
        }
        $admin = AdminShift::query()->whereKey($operation->payload['shiftId'] ?? $operation->source_id)->lockForUpdate()->firstOrFail();
        $fields = ['gross_halalas' => ['sales_amount', 'salesHalalas'], 'cards_halalas' => [null, 'cardTotalHalalas'],
            'apps_halalas' => [null, 'aggregatorTotalsHalalas'], 'counted_halalas' => ['cash_actual', 'cashActualHalalas'],
            'expected_halalas' => ['cash_expected', 'cashExpectedHalalas'], 'variance_halalas' => ['variance', 'varianceHalalas']];
        foreach ($fields as $countField => [$adminField, $payloadKey]) {
            if (! array_key_exists($payloadKey, $operation->payload ?? [])
                || (int) $operation->payload[$payloadKey] !== (int) $count->$countField
                || ($adminField !== null && (int) $admin->$adminField !== (int) $count->$countField)) {
                throw new ConflictHttpException('STALE_SHF_REPORT_SNAPSHOT');
            }
        }
        $evidence = (string) ($count->evidence_revision_id ?? $count->counted_revision_id);
        $allocation = DB::table('shift_liability_allocations')->where('cashier_shift_id', $sourceId)
            ->whereNull('superseded_at')->orderBy('id')->lockForUpdate()->first();
        if ((string) $membership->report_revision !== $evidence
            || ($allocation && (int) $membership->allocation_version !== (int) $allocation->version)) {
            throw new ConflictHttpException('STALE_DAY_MEMBER_EVIDENCE');
        }
        if ($requireReady) {
            app(\Modules\Shift\Liability\ShiftLiabilityService::class)->assertDailyReportReady(
                app(\Modules\Shift\Liability\LiabilityEvidenceSource::class)->report($sourceId)
            );
        }

        return ['source_id' => $sourceId, 'day_id' => (string) $day->id, 'revision_id' => (string) $revision->id,
            'count_id' => (string) $count->id, 'evidence_revision_id' => $evidence,
            'allocation_version' => $allocation ? (int) $allocation->version : null];
    }

    private function memberOperation(string $sourceId, bool $required = true): ?Operation
    {
        $adminIds = DB::table('asab_shifts')->where('legacy_shift_id', $sourceId)->where('role', 'cashier')->pluck('id');
        $operations = Operation::query()->where('module_key', 'shifts')->whereNull('corrective_ref_id')
            ->where(function ($query) use ($adminIds, $sourceId) {
                $query->whereIn('payload->shiftId', $adminIds)->orWhereIn('source_id', $adminIds)
                    ->orWhere('payload->legacyShiftId', $sourceId);
            })->orderBy('id')->lockForUpdate()->get();
        if ($operations->isEmpty() && ! $required) return null;
        if ($operations->count() !== 1) {
            throw new ConflictHttpException('REPORT_OPERATION_MAPPING_REQUIRED');
        }

        return $operations->first();
    }

    private function lockScope(array $sourceIds, array $dayIds, array $additionalDates = [], array $additionalOwners = []): void
    {
        $this->requireTransaction();
        $sourceIds = $this->sorted($sourceIds);
        $dayIds = $this->sorted($dayIds);
        // Discovery is read-only. Close over original daily membership and physical receipt endpoints.
        do {
            $previous = [$sourceIds, $dayIds];
            foreach (BranchManagerShift::withoutEagerLoads()->whereIn('id', $dayIds)->get() as $day) {
                if (! $day->daily_report_submitted) {
                    $scope = app(ShiftDailyCloseScopeService::class)->resolve($day, false);
                    $sourceIds = array_merge($sourceIds, $scope['cashier_shift_ids']);
                }
            }
            $sourceIds = $this->sorted($sourceIds);
            $sources = CashierShift::withoutEagerLoads()->whereIn('id', $sourceIds)->get();
            foreach ($sources as $source) {
                $branch = $source->shift()->value('branch_id');
                $dayIds = array_merge($dayIds, BranchManagerShift::where('branch_id', $branch)
                    ->where(function ($query) use ($source, $additionalDates) {
                        $query->whereDate('shift_date', $source->shift_date);
                        foreach ($additionalDates as $date) {
                            $query->orWhereDate('shift_date', $date);
                        }
                    })->pluck('id')->all());
            }
            $locks = DB::table('shift_liability_daily_locks')
                ->where(fn ($query) => $query->whereIn('cashier_shift_id', $sourceIds)->orWhereIn('branch_manager_shift_id', $dayIds))->get();
            $sourceIds = array_merge($sourceIds, $locks->pluck('cashier_shift_id')->all());
            $dayIds = array_merge($dayIds, $locks->pluck('branch_manager_shift_id')->all());
            $rejectedIncoming = DB::table('shift_transfer_rejection_evidence')->whereIn('receiving_cashier_shift_id', $sourceIds)
                ->orWhere(fn ($query) => $query->where('recipient_type', 'cashier')->whereIn('recipient_id', $sources->pluck('cashier_id')))->get();
            $rejectedAttempts = DB::table('shift_transfer_attempts')->whereIn('id', $rejectedIncoming->pluck('transfer_attempt_id'))->get();
            $rejectedRequests = $rejectedIncoming->pluck('cashier_shift_handover_id')->filter()->all();
            $rejectedTransfers = $rejectedIncoming->pluck('branch_manager_cash_transfer_id')->filter()->all();
            foreach ($rejectedAttempts as $attempt) {
                if ($attempt->request_type === 'manager_transfer') $rejectedTransfers[] = $attempt->request_id;
                else $rejectedRequests[] = $attempt->request_id;
            }
            $sourceIds = $this->sorted(array_merge($sourceIds,
                DB::table('cashier_shift_handovers')->whereIn('id', $rejectedRequests)->pluck('cashier_shift_id')->all()));
            $rejectedTransferSources = DB::table('branch_manager_cash_transfers')->whereIn('id', $rejectedTransfers)->get();
            $dayIds = $this->sorted(array_merge($dayIds, $rejectedTransferSources->pluck('branch_manager_shift_id')->all()));
            $sourceIds = $this->sorted(array_merge($sourceIds, $rejectedTransferSources->pluck('destination_cashier_shift_id')->all()));
            $handoverIds = DB::table('cashier_shift_handovers')->whereIn('cashier_shift_id', $sourceIds)->pluck('id')->all();
            $transfers = DB::table('branch_manager_cash_transfers')->whereIn('branch_manager_shift_id', $dayIds)
                ->orWhereIn('destination_cashier_shift_id', $sourceIds)->get();
            $receipts = DB::table('cashier_shift_handover_receipts')->whereIn('cashier_shift_handover_id', $handoverIds)
                ->orWhereIn('branch_manager_cash_transfer_id', $transfers->pluck('id'))
                ->orWhereIn('receiving_cashier_shift_id', $sourceIds)->orWhereIn('receiving_branch_manager_shift_id', $dayIds)->get();
            $incomingSources = DB::table('cashier_shift_handovers')->whereIn('id', $receipts->pluck('cashier_shift_handover_id'))->pluck('cashier_shift_id')->all();
            $sourceIds = $this->sorted(array_merge($sourceIds, $incomingSources, $transfers->pluck('destination_cashier_shift_id')->all(), $receipts->pluck('receiving_cashier_shift_id')->all()));
            $dayIds = $this->sorted(array_merge($dayIds, $transfers->pluck('branch_manager_shift_id')->all(), $receipts->pluck('receiving_branch_manager_shift_id')->all()));
        } while ($previous !== [$sourceIds, $dayIds]);

        $days = BranchManagerShift::withoutEagerLoads()->whereIn('id', $dayIds)->get();
        $cashierOwners = $sources->pluck('cashier_id')->all();
        $managerOwners = $days->pluck('branch_manager_id')->all();
        foreach ($additionalOwners as $owner) {
            if ($owner['type'] === 'cashier') {
                $cashierOwners[] = $owner['id'];
            } elseif ($owner['type'] === 'branch_manager') {
                $managerOwners[] = $owner['id'];
            }
        }
        $cashierOwners = array_merge($cashierOwners, DB::table('cashier_custody_transactions')
            ->whereIn('related_shift_id', $sourceIds)->where('transaction_type', 'Variance')->pluck('cashier_id')->all());
        foreach (['shift_liability_shares', 'shift_liability_posting_states', 'shift_liability_legacy_posting_links'] as $table) {
            $query = DB::table($table);
            if ($table === 'shift_liability_shares') {
                $query->whereIn('allocation_id', DB::table('shift_liability_allocations')->whereIn('cashier_shift_id', $sourceIds)->select('id'));
            } else {
                $query->whereIn('cashier_shift_id', $sourceIds);
            }
            foreach ($query->get(['responsible_type', 'responsible_id']) as $stakeholder) {
                if ($stakeholder->responsible_type === 'cashier') {
                    $cashierOwners[] = $stakeholder->responsible_id;
                } elseif ($stakeholder->responsible_type === 'branch_manager') {
                    $managerOwners[] = $stakeholder->responsible_id;
                }
            }
        }
        $managerOwners = $this->sorted($managerOwners);
        $cashierOwners = $this->sorted($cashierOwners);
        foreach ($managerOwners as $id) {
            app(ShiftTransferReceiptService::class)->lockManagerCashOwner($id);
        }
        app(ShiftCashAvailabilityService::class)->lockCashierOwners($cashierOwners);
        $lockedDays = $this->rows('branch_manager_shifts', 'id', $dayIds);
        foreach ($lockedDays as $day) {
            $before = $days->firstWhere('id', $day->id);
            if (! $before || $day->branch_manager_id !== $before->branch_manager_id || $day->branch_id !== $before->branch_id) {
                throw new ConflictHttpException('REPORT_AFFECTED_SET_CHANGED');
            }
        }
        $lockedSources = $this->rows('cashier_shifts', 'id', $sourceIds);
        foreach ($lockedSources as $source) {
            $before = $sources->firstWhere('id', $source->id);
            if (! $before || $source->cashier_id !== $before->cashier_id || $source->shift_id !== $before->shift_id
                || (string) $source->shift_date !== (string) $before->getRawOriginal('shift_date')) {
                throw new ConflictHttpException('REPORT_SOURCE_CHANGED');
            }
        }
        $requests = $this->rows('cashier_shift_handovers', 'cashier_shift_id', $sourceIds);
        $transferRows = DB::table('branch_manager_cash_transfers')->whereIn('branch_manager_shift_id', $dayIds)
            ->orWhereIn('destination_cashier_shift_id', $sourceIds)->orderBy('id')->lockForUpdate()->get();
        $receiptRows = DB::table('cashier_shift_handover_receipts')->whereIn('cashier_shift_handover_id', $requests->pluck('id'))
            ->orWhereIn('branch_manager_cash_transfer_id', $transferRows->pluck('id'))
            ->orWhereIn('receiving_cashier_shift_id', $sourceIds)->orWhereIn('receiving_branch_manager_shift_id', $dayIds)
            ->orderBy('id')->lockForUpdate()->get();
        $membership = DB::table('shift_liability_daily_locks')->whereIn('cashier_shift_id', $sourceIds)
            ->orWhereIn('branch_manager_shift_id', $dayIds)->orderBy('id')->lockForUpdate()->get();
        if (array_diff($receiptRows->pluck('receiving_cashier_shift_id')->filter()->all(), $sourceIds)
            || array_diff($receiptRows->pluck('receiving_branch_manager_shift_id')->filter()->all(), $dayIds)
            || array_diff($transferRows->pluck('destination_cashier_shift_id')->filter()->all(), $sourceIds)
            || array_diff($transferRows->pluck('branch_manager_shift_id')->filter()->all(), $dayIds)
            || array_diff($membership->pluck('cashier_shift_id')->all(), $sourceIds)
            || array_diff($membership->pluck('branch_manager_shift_id')->all(), $dayIds)) {
            throw new ConflictHttpException('REPORT_AFFECTED_SET_CHANGED');
        }
        $attemptIds = DB::table('shift_transfer_attempts')->whereIn('request_id', array_merge($requests->pluck('id')->all(), $transferRows->pluck('id')->all()))
            ->orderBy('id')->lockForUpdate()->pluck('id')->all();
        $this->rows('shift_transfer_returns', 'transfer_attempt_id', $attemptIds);
        $lockedRejections = DB::table('shift_transfer_rejection_evidence')->whereIn('receiving_cashier_shift_id', $sourceIds)
            ->orWhere(fn ($query) => $query->where('recipient_type', 'cashier')->whereIn('recipient_id', $lockedSources->pluck('cashier_id')))
            ->orderBy('id')->lockForUpdate()->get();
        if (array_diff($lockedRejections->pluck('transfer_attempt_id')->filter()->all(), $attemptIds)
            || array_diff($lockedRejections->pluck('cashier_shift_handover_id')->filter()->all(), $requests->pluck('id')->all())
            || array_diff($lockedRejections->pluck('branch_manager_cash_transfer_id')->filter()->all(), $transferRows->pluck('id')->all())) {
            throw new ConflictHttpException('REPORT_AFFECTED_SET_CHANGED');
        }
        $admin = $this->rows('asab_shifts', 'legacy_shift_id', array_merge($sourceIds, $dayIds));
        DB::table('asab_operations')->where(fn ($query) => $query->whereIn('source_id', array_merge($sourceIds, $dayIds, $admin->pluck('id')->all()))
            ->orWhereIn('payload->shiftId', $admin->pluck('id'))->orWhereIn('payload->legacyShiftId', $sourceIds)
            ->orWhereIn('payload->managerShiftId', $dayIds))->orderBy('id')->lockForUpdate()->get();
        $aggregate = $this->rows('shift_report_aggregates', 'source_id', array_merge($sourceIds, $dayIds));
        $revisions = $this->rows('shift_report_revisions', 'report_aggregate_id', $aggregate->pluck('id')->all());
        $this->rows('shift_report_cash_counts', 'report_revision_id', $revisions->pluck('id')->all());
        $this->rows('shift_sales_breakdown', 'cashier_shift_id', $sourceIds);
        $this->rows('shift_report_revision_snapshots', 'report_revision_id', $revisions->pluck('id')->all());
        $this->rows('shift_variance_details', 'cashier_shift_id', $sourceIds);
        $this->rows('shift_variance_review_evidence', 'report_revision_id', $revisions->pluck('id')->all());
        $allocations = $this->rows('shift_liability_allocations', 'cashier_shift_id', $sourceIds);
        $shares = $this->rows('shift_liability_shares', 'allocation_id', $allocations->pluck('id')->all());
        $this->rows('shift_report_manager_reviews', 'cashier_shift_id', $sourceIds);
        $legacyLinks = DB::table('shift_liability_legacy_posting_links')->whereIn('cashier_shift_id', $sourceIds)
            ->orderBy('lineage_key')->orderBy('id')->lockForUpdate()->get();
        $states = DB::table('shift_liability_posting_states')->whereIn('cashier_shift_id', $sourceIds)
            ->orderBy('lineage_key')->orderBy('id')->lockForUpdate()->get();
        foreach ($shares->concat($legacyLinks)->concat($states) as $stakeholder) {
            if (($stakeholder->responsible_type === 'cashier' && ! in_array((string) $stakeholder->responsible_id, $cashierOwners, true))
                || ($stakeholder->responsible_type === 'branch_manager' && ! in_array((string) $stakeholder->responsible_id, $managerOwners, true))) {
                throw new ConflictHttpException('REPORT_AFFECTED_SET_CHANGED');
            }
        }
        // The inherited posting writer runs only after these locks; its financial semantics remain intact.
    }

    private function rows(string $table, string $column, array $ids): \Illuminate\Support\Collection
    {
        return DB::table($table)->whereIn($column, $ids)->orderBy('id')->lockForUpdate()->get();
    }

    private function sorted(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => $id === null ? '' : (string) $id, $ids))));
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Report review requires a root transaction.');
        }
    }
}
