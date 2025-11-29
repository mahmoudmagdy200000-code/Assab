<?php

namespace Modules\Shift\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftHistoryAction;
use Modules\Shift\Enums\ShiftStatus;

class HandoverService
{
    /**
     * Approve a shift handover - Section C
     */
    public function approveHandover(
        CashierShift $shift,
        string $reviewerId,
        string $reviewerType,
        ?string $managerComment = null
    ): CashierShift {
        DB::beginTransaction();
        try {
            // Ensure handoverStatus exists
            if (!$shift->handoverStatus) {
                ShiftHandoverStatus::create([
                    'cashier_shift_id' => $shift->id,
                    'status' => HandoverStatus::PENDING,
                    'manager_approval_status' => 'pending',
                ]);
                $shift->refresh();
            }

            // Check if can be approved
            if (!in_array($shift->handoverStatus->manager_approval_status, ['pending', 'rejected'])) {
                throw new \Exception('Handover cannot be approved in current state');
            }

            // Update handover status
            $shift->handoverStatus->update([
                'status' => HandoverStatus::ACCEPTED,
                'manager_approval_status' => 'approved', // NEW
                'reviewed_by_id' => $reviewerId,
                'reviewed_by_type' => $reviewerType,
                'manager_comment' => $managerComment,
                'reviewed_at' => Carbon::now(),
                'rejection_count' => 0, // Reset on approval
            ]);

            // Mark shift as completed
            $shift->update([
                'status' => ShiftStatus::COMPLETED,
            ]);

            // Try auto handover safely
            try {
                $this->autoHandover($shift);
            } catch (\Throwable $ex) {
                Log::warning('Auto handover failed but approval succeeded', [
                    'shift_id' => $shift->id,
                    'error' => $ex->getMessage(),
                ]);
            }

            // Record history
            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_APPROVED->value,
                ['status' => HandoverStatus::PENDING->value],
                [
                    'status' => HandoverStatus::ACCEPTED->value,
                    'manager_approval_status' => 'approved',
                    'reviewed_by_id' => $reviewerId,
                    'reviewed_by_type' => $reviewerType,
                ],
                $managerComment
            );

            // Reload relationships
            $shift->load([
                'handoverStatus.reviewedBy',
                'nextCashier',
                'cashier',
                'shift',
                'salesBreakdown.aggregator',
                'varianceDetails.responsibleCashier',
            ]);

            DB::commit();
            return $shift;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve handover', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Reject a handover - Section C (with 2-rejection rule)
     */
    public function rejectHandover(
        CashierShift $shift,
        string $reviewerId,
        string $reviewerType,
        string $reason,
        array $files = [],
        ?string $comment = null
    ): array {
        DB::beginTransaction();
        try {
            $handoverStatus = $shift->handoverStatus;

            // Check current rejection count
            if ($handoverStatus->rejection_count >= 2) {
                throw new \Exception('This handover has already been rejected twice (permanently rejected)');
            }

            // Upload rejection files
            $uploadedFiles = [];
            foreach ($files as $file) {
                $filename = 'rejection_' . $shift->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('handover_rejections', $filename, 'public');
                $uploadedFiles[] = $path;
            }

            // Increment rejection count
            $newRejectionCount = $handoverStatus->rejection_count + 1;
            $isFinalRejection = $newRejectionCount >= 2;

            // Prepare update data
            $updateData = [
                'status' => HandoverStatus::REJECTED,
                'manager_approval_status' => $isFinalRejection ? 'rejected_final' : 'rejected',
                'reviewed_by_id' => $reviewerId,
                'reviewed_by_type' => $reviewerType,
                'rejection_reason' => $reason,
                'rejection_files' => $uploadedFiles ? json_encode(array_merge(
                    $handoverStatus->rejection_files ?? [],
                    $uploadedFiles
                )) : $handoverStatus->rejection_files,
                'manager_comment' => $comment,
                'reviewed_at' => now(),
                'rejection_count' => $newRejectionCount,
            ];

            // Track rejection timestamps
            if ($newRejectionCount === 1) {
                $updateData['first_rejected_at'] = now();
            } elseif ($newRejectionCount === 2) {
                $updateData['second_rejected_at'] = now();
            }

            $handoverStatus->update($updateData);

            // Record history
            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_REJECTED->value,
                ['status' => $handoverStatus->status->value],
                [
                    'status' => HandoverStatus::REJECTED->value,
                    'manager_approval_status' => $updateData['manager_approval_status'],
                    'reviewed_by_id' => $reviewerId,
                    'reviewed_by_type' => $reviewerType,
                    'rejection_reason' => $reason,
                    'rejection_count' => $newRejectionCount,
                    'is_final_rejection' => $isFinalRejection,
                ]
            );

            DB::commit();

            return [
                'shift_id' => $shift->id,
                'handover_status' => $updateData['manager_approval_status'],
                'rejection_count' => $newRejectionCount,
                'is_final_rejection' => $isFinalRejection,
                'can_cashier_edit' => !$isFinalRejection,
                'rejection_reason' => $reason,
                'rejected_at' => now()->format('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject handover', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Record handover edit after rejection - Section C
     */
    public function recordHandoverEdit(CashierShift $shift, array $data): CashierShift
    {
        DB::beginTransaction();
        try {
            $handoverStatus = $shift->handoverStatus;

            // Verify can edit
            if (!$handoverStatus->canCashierEdit()) {
                throw new \Exception('Handover cannot be edited. Either not rejected or permanently rejected.');
            }

            // Update shift data
            $shift->update([
                'closing_balance' => $data['handover_amount'],
                'handover_notes' => $data['handover_notes'] ?? $shift->handover_notes,
            ]);

            // Recalculate variance
            $expectedBalance = $shift->total_sales;
            $variance = $expectedBalance - $data['handover_amount'];
            $shift->update([
                'expected_balance' => $expectedBalance,
                'variance' => $variance,
            ]);

            // Mark as edited and reset to pending
            $handoverStatus->update([
                'status' => HandoverStatus::PENDING,
                'manager_approval_status' => 'pending',
                'was_edited_after_rejection' => true,
                'edited_at' => now(),
                'rejection_reason' => null, // Clear previous rejection reason
                'manager_comment' => null,
            ]);

            // Record history
            $shift->recordHistory(
                'handover_edited_after_rejection',
                ['status' => 'rejected'],
                [
                    'status' => 'pending',
                    'handover_amount' => $data['handover_amount'],
                    'variance' => $variance,
                    'rejection_count' => $handoverStatus->rejection_count,
                ]
            );

            DB::commit();
            return $shift->fresh(['handoverStatus', 'nextCashier']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Accept a handover
     */
    public function acceptHandover(
        CashierShift $shift,
        string $reviewerId,
        string $reviewerType,
        ?string $comment = null
    ): void {
        DB::beginTransaction();
        try {
            $shift->handoverStatus->update([
                'status' => HandoverStatus::ACCEPTED,
                'manager_approval_status' => 'approved',
                'reviewed_by_id' => $reviewerId,
                'reviewed_by_type' => $reviewerType,
                'manager_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_ACCEPTED->value,
                ['status' => HandoverStatus::PENDING->value],
                [
                    'status' => HandoverStatus::ACCEPTED->value,
                    'reviewed_by_id' => $reviewerId,
                    'reviewed_by_type' => $reviewerType,
                ]
            );

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Record handover
     */
    public function recordHandover(CashierShift $shift, array $data): CashierShift
    {
        DB::beginTransaction();
        try {
            Log::info('Recording handover', [
                'shift_id' => $shift->id,
                'next_cashier_id' => $data['next_cashier_id'],
            ]);

            $shift->update([
                'next_cashier_id' => $data['next_cashier_id'],
                'closing_balance' => $data['handover_amount'],
                'handover_notes' => $data['handover_notes'] ?? null,
                'handed_over_at' => now(),
            ]);

            $expectedBalance = $shift->total_sales;
            $variance = $expectedBalance - $data['handover_amount'];

            $shift->update([
                'expected_balance' => $expectedBalance,
                'variance' => $variance,
            ]);

            ShiftHandoverStatus::create([
                'cashier_shift_id' => $shift->id,
                'status' => HandoverStatus::PENDING,
                'manager_approval_status' => 'pending',
            ]);

            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_RECORDED->value,
                null,
                [
                    'next_cashier_id' => $data['next_cashier_id'],
                    'handover_amount' => $data['handover_amount'],
                    'variance' => $variance,
                ]
            );

            DB::commit();

            Log::info('Handover recorded successfully', [
                'shift_id' => $shift->id,
                'next_cashier_id' => $shift->next_cashier_id,
            ]);

            return $shift->fresh(['nextCashier']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to record handover', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Automatically record a handover to the next shift
     */
    public function autoHandover(CashierShift $endedShift): ?CashierShift
    {
        try {
            if ($endedShift->handoverStatus?->status === HandoverStatus::ACCEPTED) {
                Log::warning('Auto handover skipped: shift already handed over', ['shift_id' => $endedShift->id]);
                return null;
            }

            $nextShift = CashierShift::where('shift_date', $endedShift->shift_date)
                ->whereHas(
                    'shift',
                    fn($q) => $q->where('start_time', '>', $endedShift->shift->end_time)
                )
                ->orderBy('shift_id')
                ->first();

            if (!$nextShift) {
                Log::info("No next shift found for auto handover", ['shift_id' => $endedShift->id]);
                return null;
            }

            $handoverAmount = $endedShift->closing_balance ?? $endedShift->total_sales ?? 0;

            $recorded = $this->recordHandover($endedShift, [
                'next_cashier_id' => $nextShift->cashier_id,
                'handover_amount' => $handoverAmount,
                'handover_notes' => 'Auto handover executed by system',
            ]);

            Log::info('Auto handover completed successfully', [
                'shift_id' => $endedShift->id,
                'next_shift_id' => $nextShift->id,
                'next_cashier_id' => $nextShift->cashier_id,
            ]);

            return $recorded;
        } catch (\Exception $e) {
            Log::error('Auto handover failed', [
                'shift_id' => $endedShift->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get handover summaries with optional filters
     */
    public function getHandoverSummaries(array $filters = []): array
    {
        try {
            $query = CashierShift::with([
                'handoverStatus',
                'cashier',
                'nextCashier',
                'shift.branch'
            ])->whereHas('handoverStatus');

            // Apply filters
            if (!empty($filters['branch_id'])) {
                $query->whereHas('shift', function ($q) use ($filters) {
                    $q->where('branch_id', $filters['branch_id']);
                });
            }

            if (!empty($filters['date_from'])) {
                $query->where('shift_date', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->where('shift_date', '<=', $filters['date_to']);
            }

            if (!empty($filters['status'])) {
                $query->whereHas('handoverStatus', function ($q) use ($filters) {
                    $q->where('manager_approval_status', $filters['status']);
                });
            }

            if (!empty($filters['cashier_id'])) {
                $query->where('cashier_id', $filters['cashier_id']);
            }

            $shifts = $query->get();

            // Calculate statistics
            $totalHandovers = $shifts->count();
            $pendingHandovers = $shifts->filter(
                fn($s) => $s->handoverStatus?->manager_approval_status === 'pending'
            )->count();

            $acceptedHandovers = $shifts->filter(
                fn($s) => $s->handoverStatus?->manager_approval_status === 'approved'
            )->count();

            $rejectedHandovers = $shifts->filter(
                fn($s) => in_array($s->handoverStatus?->manager_approval_status, ['rejected', 'rejected_final'])
            )->count();

            $finalRejectedHandovers = $shifts->filter(
                fn($s) => $s->handoverStatus?->manager_approval_status === 'rejected_final'
            )->count();

            // Calculate variance statistics
            $totalVariance = $shifts->sum('variance');
            $avgVariance = $totalHandovers > 0 ? $shifts->avg('variance') : 0;

            $overages = $shifts->filter(fn($s) => $s->variance > 0);
            $shortages = $shifts->filter(fn($s) => $s->variance < 0);

            $totalOverage = $overages->sum('variance');
            $totalShortage = abs($shortages->sum('variance'));

            // Calculate amounts
            $totalHandoverAmount = $shifts->sum('closing_balance');
            $totalExpectedAmount = $shifts->sum('expected_balance');

            // Recent handovers (last 10)
            $recentHandovers = $shifts->sortByDesc('handed_over_at')
                ->take(10)
                ->map(function ($shift) {
                    return [
                        'shift_id' => $shift->id,
                        'shift_date' => $shift->shift_date,
                        'cashier_name' => $shift->cashier?->name,
                        'next_cashier_name' => $shift->nextCashier?->name,
                        'handover_amount' => (float) $shift->closing_balance,
                        'variance' => (float) $shift->variance,
                        'status' => $shift->handoverStatus?->manager_approval_status,
                        'rejection_count' => $shift->handoverStatus?->rejection_count ?? 0,
                        'handed_over_at' => $shift->handed_over_at?->format('Y-m-d H:i:s'),
                        'branch_name' => $shift->shift?->branch?->name,
                    ];
                })
                ->values();

            return [
                'overview' => [
                    'total_handovers' => $totalHandovers,
                    'pending' => $pendingHandovers,
                    'approved' => $acceptedHandovers,
                    'rejected' => $rejectedHandovers,
                    'rejected_final' => $finalRejectedHandovers,
                    'acceptance_rate' => $totalHandovers > 0
                        ? round(($acceptedHandovers / $totalHandovers) * 100, 2)
                        : 0,
                    'rejection_rate' => $totalHandovers > 0
                        ? round(($rejectedHandovers / $totalHandovers) * 100, 2)
                        : 0,
                ],
                'financial_summary' => [
                    'total_handover_amount' => (float) $totalHandoverAmount,
                    'total_expected_amount' => (float) $totalExpectedAmount,
                    'total_variance' => (float) $totalVariance,
                    'average_variance' => (float) round($avgVariance, 2),
                    'total_overage' => (float) $totalOverage,
                    'total_shortage' => (float) $totalShortage,
                    'variance_breakdown' => [
                        'overages_count' => $overages->count(),
                        'shortages_count' => $shortages->count(),
                        'exact_matches' => $shifts->filter(fn($s) => $s->variance == 0)->count(),
                    ],
                ],
                'recent_handovers' => $recentHandovers,
                'filters_applied' => $filters,
                'generated_at' => now()->format('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate handover summaries', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $filters,
            ]);

            throw $e;
        }
    }
}
