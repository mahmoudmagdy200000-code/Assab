<?php

namespace Modules\Shift\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftReportRevision;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * S1-11 Task 4.1: Service for authorized in-flight reopen of cashier shift reports within unsubmitted workdays.
 */
class ShiftReportReopenService
{
    public function __construct(
        private ShiftReportRevisionService $revisions,
        private ShiftReportRevisionSnapshotService $snapshots,
        private ShiftCashCountService $counts,
    ) {}

    /**
     * Reopens an ended cashier report before workday submission, preserving operational status and recording revision audit.
     * Command callers must use required transactional idempotency; operationId is the audit identity, not a replay store.
     */
    public function reopenCashierReport(
        CashierShift $source,
        Model $actor,
        int $expectedRevision,
        string $reason,
        string $operationId
    ): ShiftReportRevision {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Reopen reason cannot be empty.',
            ]);
        }

        $operationId = trim($operationId);
        if ($operationId === '' || strlen($operationId) > 100) {
            throw ValidationException::withMessages([
                'operation_id' => 'Operation ID cannot be empty.',
            ]);
        }

        return DB::transaction(function () use ($source, $actor, $expectedRevision, $reason, $operationId) {
            $shift = app(ShiftReportMutationGuard::class)->lockEditable($source, $actor);
            $branchId = $shift->shift()->value('branch_id');
            if ((string) $shift->getRawOriginal('status') !== 'completed' || $shift->actual_end_time === null) {
                throw new ConflictHttpException('REPORT_NOT_ENDED');
            }

            // Lock and verify report aggregate & expected revision
            /** @var ShiftReportAggregate $aggregate */
            $aggregate = ShiftReportAggregate::query()
                ->where('source_type', 'cashier_shift')
                ->where('source_id', $shift->id)
                ->lockForUpdate()
                ->first();

            if (! $aggregate || $aggregate->current_revision_number !== $expectedRevision) {
                throw new ConflictHttpException('STALE_REPORT_REVISION');
            }

            // Read current revision
            $previousRevision = $aggregate->revisions()
                ->where('revision_number', $aggregate->current_revision_number)
                ->lockForUpdate()
                ->first();

            // Preserve reviews and snapshot on previous revision before any mutations
            if ($previousRevision) {
                $this->snapshots->preserveVarianceReviews($shift, $previousRevision);
                $this->snapshots->createSnapshotIfMissing($previousRevision, $shift);
            }

            // Advance report revision
            $actorType = $actor->getMorphClass();
            $newRevision = $this->revisions->recordCashierRevision($shift, $actorType, $actor->id, $expectedRevision);

            // Handle count carry-forward or physical recount requirement
            if (! $aggregate->fresh_count_required && $previousRevision) {
                $this->counts->carryForward($previousRevision, $newRevision);
            }

            // Record fine-grained correction audit row for the reopen
            $companyId = $shift->shift?->branch?->asab_company_id;
            ShiftReportCorrection::create([
                'report_aggregate_id' => $aggregate->id,
                'previous_revision_id' => $previousRevision?->id,
                'new_revision_id' => $newRevision->id,
                'previous_revision_number' => $previousRevision?->revision_number,
                'new_revision_number' => $newRevision->revision_number,
                'field_name' => 'report_status',
                'field_type' => 'string',
                'old_value' => 'submitted',
                'new_value' => 'reopened',
                'old_halalas' => null,
                'new_halalas' => null,
                'reason' => $reason,
                'actor_type' => $actorType,
                'actor_id' => $actor->id,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'operation_id' => $operationId,
            ]);

            // Supersede existing liability allocations
            ShiftLiabilityAllocation::where('cashier_shift_id', $shift->id)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            // Record cashier shift history
            $shift->history()->create([
                'action' => 'report_reopened',
                'performed_by' => (string) $actor->getKey(),
                'performed_by_type' => $actorType,
                'old_value' => [
                    'status' => 'submitted',
                ],
                'new_value' => [
                    'status' => 'reopened',
                    'revision_number' => $newRevision->revision_number,
                    'operation_id' => $operationId,
                    'reason' => $reason,
                ],
                'notes' => $reason,
            ]);

            // Create snapshot for the new revision
            $this->snapshots->createSnapshotIfMissing($newRevision, $shift);
            app(ShiftReportCacheInvalidator::class)->cashierReport($shift);

            return $newRevision;
        });
    }
}
