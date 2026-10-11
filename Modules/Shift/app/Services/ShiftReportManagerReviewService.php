<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\BranchManagerService;
use Modules\Shift\Liability\AllocationRules;
use Modules\Shift\Liability\ResponsibleActorResolver;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportManagerReview;
use Modules\Shift\Models\ShiftReportRevision;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShiftReportManagerReviewService
{
    public function __construct(
        private BranchManagerService $branchManagers,
    ) {}

    public function requireFor(
        CashierShift $shift,
        ShiftReportRevision $evidenceRevision,
        int $varianceHalalas,
        ?string $reason = null
    ): ShiftReportManagerReview {
        $branchId = $shift->shift()->value('branch_id') ?? $shift->branch_id;
        $companyId = $shift->shift?->branch?->asab_company_id;

        $review = ShiftReportManagerReview::updateOrCreate(
            ['report_revision_id' => $evidenceRevision->id],
            [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'cashier_shift_id' => $shift->id,
                'report_revision_number' => $evidenceRevision->revision_number,
                'status' => 'pending',
                'variance_halalas' => $varianceHalalas,
                'has_shortage' => $varianceHalalas < 0,
                'reason' => $reason,
            ]
        );

        ShiftReportAggregate::query()
            ->where('source_type', 'cashier_shift')
            ->where('source_id', $shift->id)
            ->update(['required_manager_review_evidence_revision_id' => $evidenceRevision->id]);

        return $review;
    }

    public function approveCurrent(
        CashierShift $shift,
        BranchManager $manager,
        int $expectedRevision,
        ?int $expectedAllocationVersion,
        ?string $reason,
        string $operationId
    ): ShiftReportManagerReview {
        return DB::transaction(function () use ($shift, $manager, $expectedRevision, $expectedAllocationVersion, $reason, $operationId) {
            $branchId = $shift->shift()->value('branch_id') ?? $shift->branch_id;
            if ((string) $manager->branch_id !== (string) $branchId) {
                throw new AccessDeniedHttpException('ONLY_REPORT_OWNER_OR_ASSIGNED_BRANCH_MANAGER');
            }
            $this->branchManagers->assertAssignedActiveManager($branchId, (string) $manager->id);

            /** @var ShiftReportAggregate $aggregate */
            $aggregate = ShiftReportAggregate::query()
                ->where('source_type', 'cashier_shift')
                ->where('source_id', $shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($aggregate->current_revision_number !== $expectedRevision) {
                throw new ConflictHttpException('STALE_REPORT_REVISION');
            }

            if (! $aggregate->required_manager_review_evidence_revision_id) {
                throw new ConflictHttpException('NO_PENDING_MANAGER_REVIEW');
            }

            /** @var ShiftReportManagerReview $review */
            $review = ShiftReportManagerReview::query()
                ->where('report_revision_id', $aggregate->required_manager_review_evidence_revision_id)
                ->lockForUpdate()
                ->firstOrFail();
            $currentRevision = ShiftReportRevision::query()
                ->where('report_aggregate_id', $aggregate->id)
                ->where('revision_number', $aggregate->current_revision_number)
                ->lockForUpdate()
                ->first();
            if ($currentRevision === null
                || $review->report_revision_id !== $currentRevision->id
                || $review->report_revision_number !== $expectedRevision) {
                throw new ConflictHttpException('STALE_REPORT_REVISION');
            }

            if ($review->has_shortage) {
                $currentCount = ShiftReportCashCount::query()
                    ->where('report_revision_id', $currentRevision->id)
                    ->lockForUpdate()
                    ->first();
                if ($currentCount === null || $currentCount->variance_halalas !== $review->variance_halalas
                    || $review->variance_halalas >= 0) {
                    throw new ConflictHttpException('LIABILITY_ALLOCATION_REQUIRED');
                }

                /** @var ShiftLiabilityAllocation|null $allocation */
                $allocation = ShiftLiabilityAllocation::query()
                    ->where('cashier_shift_id', $shift->id)
                    ->whereNull('superseded_at')
                    ->lockForUpdate()
                    ->first();

                if (! $allocation || $expectedAllocationVersion === null || $allocation->version !== $expectedAllocationVersion
                    || $allocation->report_revision !== (string) $expectedRevision
                    || $allocation->variance_halalas !== $review->variance_halalas
                    || $allocation->manager_approval_status !== 'pending') {
                    throw new ConflictHttpException($allocation && $expectedAllocationVersion !== null && $allocation->version !== $expectedAllocationVersion
                        ? 'STALE_ALLOCATION_VERSION'
                        : 'LIABILITY_ALLOCATION_REQUIRED');
                }

                $shares = $allocation->shares()->lockForUpdate()->get();
                try {
                    AllocationRules::validate($review->variance_halalas, $shares->map(fn ($share) => [
                        'type' => $share->responsible_type,
                        'id' => (string) $share->responsible_id,
                        'amount' => (int) $share->amount_halalas,
                    ])->all());
                } catch (\InvalidArgumentException $exception) {
                    throw new ConflictHttpException('ALLOCATION_INCOMPLETE', $exception);
                }
                $branchId = $shift->shift()->value('branch_id') ?? $shift->branch_id;
                $companyId = $shift->shift?->branch?->asab_company_id;
                if ((string) $allocation->branch_id !== (string) $branchId
                    || (string) $allocation->company_id !== (string) $companyId
                    || (string) $allocation->cashier_shift_id !== (string) $shift->id) {
                    throw new ConflictHttpException('LIABILITY_ALLOCATION_REQUIRED');
                }
                $resolver = app(ResponsibleActorResolver::class);
                foreach ($shares as $share) {
                    $resolver->resolve($share->responsible_type, $share->responsible_id, $companyId, $branchId);
                }

                $allocation->update([
                    'manager_approval_status' => 'approved',
                    'manager_approved_by' => $manager->id,
                    'manager_approved_at' => now(),
                ]);
            }

            $review->update([
                'status' => 'approved',
                'reviewer_type' => 'branch_manager',
                'reviewer_id' => $manager->id,
                'reviewed_at' => now(),
                'reason' => $reason,
            ]);

            $aggregate->update([
                'required_manager_review_evidence_revision_id' => null,
            ]);

            // Reconcile liability posting deltas per approved user policy:
            // "يبقى الأثر المرحّل السابق أثناء انتظار اعتماد التصحيح، ثم يُرحّل الفرق فقط بعد اعتماد البديل."
            app(ShortagePostingService::class)->reconcile(
                $shift,
                $manager,
                $expectedRevision,
                $expectedAllocationVersion,
                $operationId
            );

            return $review;
        }, 3);
    }

    public function assertReadyForWorkday(BranchManagerShift $day, array $cashierShiftIds): void
    {
        if (empty($cashierShiftIds)) {
            return;
        }

        $aggregates = ShiftReportAggregate::query()
            ->where('source_type', 'cashier_shift')
            ->whereIn('source_id', $cashierShiftIds)
            ->whereNotNull('required_manager_review_evidence_revision_id')
            ->get();

        foreach ($aggregates as $aggregate) {
            $approved = ShiftReportManagerReview::query()
                ->where('report_revision_id', $aggregate->required_manager_review_evidence_revision_id)
                ->where('status', 'approved')
                ->exists();

            if (! $approved) {
                throw new ConflictHttpException('BRANCH_MANAGER_REVIEW_REQUIRED');
            }
        }
    }
}
