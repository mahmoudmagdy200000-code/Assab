<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Models\ShiftReportRevisionSnapshot;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Models\ShiftVarianceDetail;
use Modules\Shift\Models\ShiftVarianceReviewEvidence;

class ShiftReportRevisionSnapshotService
{
    /**
     * Creates an immutable snapshot for a given report revision if one does not already exist.
     */
    public function createSnapshotIfMissing(
        ShiftReportRevision $revision,
        CashierShift|BranchManagerShift $shift
    ): ?ShiftReportRevisionSnapshot {
        $existing = ShiftReportRevisionSnapshot::where('report_revision_id', $revision->id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
        if ($existing) {
            return $existing;
        }

        $salesBreakdown = [];
        $varianceDetails = [];

        if ($shift instanceof CashierShift) {
            $salesBreakdown = ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'aggregator_id' => $item->aggregator_id,
                    'amount' => (string) $item->amount,
                    'amount_halalas' => (int) round((float) $item->amount * 100),
                    'notes' => $item->notes,
                ])
                ->values()
                ->toArray();

            $varianceDetails = ShiftVarianceDetail::where('cashier_shift_id', $shift->id)
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'responsibility_type' => $item->responsibility_type?->value ?? (string) $item->responsibility_type,
                    'responsible_cashier_id' => $item->responsible_cashier_id,
                    'variance_amount' => (string) $item->variance_amount,
                    'variance_amount_halalas' => (int) round((float) $item->variance_amount * 100),
                    'variance_type' => $item->variance_type?->value ?? (string) $item->variance_type,
                    'assigned_amount' => (string) $item->assigned_amount,
                    'assigned_amount_halalas' => (int) round((float) $item->assigned_amount * 100),
                    'reason' => $item->reason,
                    'supporting_files' => $item->supporting_files,
                    'responsibility_status' => $item->responsibility_status ?? 'pending',
                    'reviewed_by_id' => $item->reviewed_by_id,
                    'reviewed_by_type' => $item->reviewed_by_type,
                    'reviewed_at' => $item->reviewed_at?->toIso8601String(),
                    'rejection_reason' => $item->rejection_reason,
                ])
                ->values()
                ->toArray();

            $varianceReviews = ShiftVarianceReviewEvidence::where('report_revision_id', $revision->id)
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'shift_variance_detail_id' => $item->shift_variance_detail_id,
                    'responsibility_status' => $item->responsibility_status,
                    'reviewed_by_id' => $item->reviewed_by_id,
                    'reviewed_by_type' => $item->reviewed_by_type,
                    'reviewed_at' => $item->reviewed_at?->toIso8601String(),
                    'rejection_reason' => $item->rejection_reason,
                ])
                ->values()
                ->toArray();

            $cashCount = ShiftReportCashCount::where('report_revision_id', $revision->id)
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
            $handover = CashierShiftHandover::where('report_revision_id', $revision->id)
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
            $allocations = ShiftLiabilityAllocation::where('cashier_shift_id', $shift->id)
                ->where('report_revision', $revision->id)
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
                ->with(['shares' => fn ($query) => $query->when(DB::transactionLevel() > 0, fn ($shares) => $shares->lockForUpdate())])
                ->get()->toArray();

            $snapshotData = [
                'shift_id' => $shift->id,
                'revision_id' => $revision->id,
                'revision_number' => $revision->revision_number,
                'created_by_type' => $revision->created_by_type,
                'created_by_id' => $revision->created_by_id,
                'status' => $shift->status?->value ?? (string) $shift->status,
                'total_sales' => (string) $shift->total_sales,
                'total_sales_halalas' => (int) round((float) $shift->total_sales * 100),
                'net_sales' => (string) $shift->net_sales,
                'vat_amount' => (string) $shift->vat_amount,
                'cash_collected' => (string) $shift->cash_collected,
                'cash_collected_halalas' => (int) round((float) $shift->cash_collected * 100),
                'card_payments' => (string) $shift->card_payments,
                'card_payments_halalas' => (int) round((float) $shift->card_payments * 100),
                'variance' => (string) $shift->variance,
                'variance_halalas' => (int) round((float) $shift->variance * 100),
                'pos_receipt' => $shift->pos_receipt,
                'closing_balance' => (string) $shift->closing_balance,
                'handover_notes' => $shift->handover_notes,
                'sales_breakdown' => $salesBreakdown,
                'variance_details' => $varianceDetails,
                'variance_reviews' => $varianceReviews,
                'cash_count' => $cashCount ? [
                    'counted_halalas' => $cashCount->counted_halalas,
                    'expected_halalas' => $cashCount->expected_halalas,
                    'variance_halalas' => $cashCount->variance_halalas,
                    'gross_halalas' => $cashCount->gross_halalas,
                    'cards_halalas' => $cashCount->cards_halalas,
                    'apps_halalas' => $cashCount->apps_halalas,
                ] : null,
                'handover' => $handover ? [
                    'id' => $handover->id,
                    'handover_to_type' => $handover->handover_to_type,
                    'handover_to_id' => $handover->handover_to_id,
                    'handover_amount' => (string) $handover->handover_amount,
                    'variance_amount' => (string) $handover->variance_amount,
                    'variance_reason' => $handover->variance_reason,
                    'variance_files' => $handover->variance_files,
                    'handover_notes' => $handover->handover_notes,
                    'status' => $handover->status,
                    'rejection_reason' => $handover->rejection_reason,
                    'rejection_count' => $handover->rejection_count,
                    'first_rejected_at' => $handover->first_rejected_at?->toIso8601String(),
                    'second_rejected_at' => $handover->second_rejected_at?->toIso8601String(),
                ] : null,
                'allocations' => $allocations,
            ];
        } else {
            $snapshotData = [
                'shift_id' => $shift->id,
                'revision_id' => $revision->id,
                'revision_number' => $revision->revision_number,
                'created_by_type' => $revision->created_by_type,
                'created_by_id' => $revision->created_by_id,
                'status' => $shift->status,
                'shift_date' => (string) $shift->shift_date,
                'cash_collected' => (string) ($shift->cash_collected ?? '0.00'),
            ];
        }

        return ShiftReportRevisionSnapshot::create([
            'report_revision_id' => $revision->id,
            'schema_version' => 1,
            'snapshot_data' => $snapshotData,
            'created_at' => now(),
        ]);
    }

    /**
     * Preserves review and approval evidence of variance responsibility for the given revision
     * before current projection details are modified or purged. Passing reviewed
     * detail IDs records a newly completed review operation as a distinct event.
     */
    public function preserveVarianceReviews(CashierShift $shift, ?ShiftReportRevision $revision = null, ?array $reviewedDetailIds = null): void
    {
        $revision ??= app(\Modules\Shift\Services\ShiftReportRevisionService::class)->currentCashierRevision($shift);
        if (! $revision) {
            return;
        }

        $reviewedDetails = ShiftVarianceDetail::where('cashier_shift_id', $shift->id)
            ->when($reviewedDetailIds !== null, fn ($query) => $query->whereIn('id', $reviewedDetailIds))
            ->where(function ($q) {
                $q->whereNotNull('reviewed_at')
                    ->orWhereIn('responsibility_status', ['approved', 'rejected']);
            })
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->get();

        foreach ($reviewedDetails as $detail) {
            // Review endpoints append one event for each actual transition. A preservation
            // pass sees the same projection again after rejection and must not relabel
            // its existing event as a review of a later report revision.
            if ($reviewedDetailIds === null && ShiftVarianceReviewEvidence::query()
                ->where('cashier_shift_id', $shift->id)
                ->where('shift_variance_detail_id', $detail->id)
                ->where('responsibility_status', $detail->responsibility_status ?? 'pending')
                ->where('reviewed_by_id', (string) $detail->reviewed_by_id)
                ->where('reviewed_by_type', (string) $detail->reviewed_by_type)
                ->where('reviewed_at', $detail->reviewed_at)
                ->where('rejection_reason', $detail->rejection_reason)
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
                ->exists()) {
                continue;
            }

            ShiftVarianceReviewEvidence::create([
                'report_revision_id' => $revision->id,
                'cashier_shift_id' => $shift->id,
                'shift_variance_detail_id' => $detail->id,
                'responsibility_status' => $detail->responsibility_status ?? 'pending',
                'reviewed_by_id' => (string) $detail->reviewed_by_id,
                'reviewed_by_type' => (string) $detail->reviewed_by_type,
                'reviewed_at' => $detail->reviewed_at,
                'rejection_reason' => $detail->rejection_reason,
                'created_at' => now(),
            ]);

            $shift->recordHistory(
                'variance_responsibility_review_preserved',
                [
                    'responsibility_status' => 'pending',
                ],
                [
                    'report_revision_id' => $revision->id,
                    'revision_number' => $revision->revision_number,
                    'variance_detail_id' => $detail->id,
                    'responsibility_status' => $detail->responsibility_status,
                    'reviewed_by_id' => (string) $detail->reviewed_by_id,
                    'reviewed_by_type' => (string) $detail->reviewed_by_type,
                    'reviewed_at' => $detail->reviewed_at?->toIso8601String(),
                    'rejection_reason' => $detail->rejection_reason,
                    'assigned_amount' => (string) $detail->assigned_amount,
                    'responsible_cashier_id' => $detail->responsible_cashier_id,
                ]
            );
        }
    }
}
