<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportRevision;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Minimum S1-08 report identity. Revision snapshots and correction history remain S1-11. */
class ShiftReportRevisionService
{
    public function recordCashierRevision(CashierShift $shift, string $actorType, string $actorId, ?int $expectedRevision = null): ShiftReportRevision
    {
        return $this->recordRevision('cashier_shift', $shift->id, $actorType, $actorId, $expectedRevision);
    }

    public function recordManagerRevision(BranchManagerShift $shift, string $actorType, string $actorId, ?int $expectedRevision = null): ShiftReportRevision
    {
        return $this->recordRevision('branch_manager_shift', $shift->id, $actorType, $actorId, $expectedRevision);
    }

    public function currentCashierRevision(CashierShift $shift): ?ShiftReportRevision
    {
        return $this->current('cashier_shift', $shift->id);
    }

    public function currentManagerRevision(BranchManagerShift $shift): ?ShiftReportRevision
    {
        return $this->current('branch_manager_shift', $shift->id);
    }

    private function recordRevision(string $sourceType, string $sourceId, string $actorType, string $actorId, ?int $expectedRevision): ShiftReportRevision
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Report revisions must be recorded inside the report transaction.');
        }

        $aggregate = ShiftReportAggregate::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();

        if (! $aggregate) {
            $aggregate = ShiftReportAggregate::create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'current_revision_number' => 0,
            ]);
        }

        if ($expectedRevision !== null && $aggregate->current_revision_number !== $expectedRevision) {
            throw new ConflictHttpException('STALE_REPORT_REVISION');
        }

        $number = $aggregate->current_revision_number + 1;
        $revision = $aggregate->revisions()->create([
            'revision_number' => $number,
            'created_by_type' => $actorType,
            'created_by_id' => $actorId,
        ]);
        $aggregate->update(['current_revision_number' => $number]);

        return $revision;
    }

    private function current(string $sourceType, string $sourceId): ?ShiftReportRevision
    {
        $aggregate = ShiftReportAggregate::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        return $aggregate?->revisions()->where('revision_number', $aggregate->current_revision_number)->first();
    }
}
