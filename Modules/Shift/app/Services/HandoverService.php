<?php

namespace Modules\Shift\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftHistoryAction;
use Modules\Shift\Enums\ShiftStatus;

/**
 * HandoverService
 *
 * Manages all handover operations including:
 * - Recording handovers (to cashier or branch manager)
 * - Approval/Rejection workflow with 2-rejection rule
 * - Auto-handover between consecutive shifts
 * - Handover summaries and statistics
 */
class HandoverService
{
    /**
     * Record a new handover
     * Supports both cashier-to-cashier and cashier-to-manager handovers
     *
     * @param CashierShift $shift
     * @param array $data
     * @return CashierShift
     */
    public function recordHandover(CashierShift $shift, array $data): CashierShift
    {
        DB::beginTransaction();
        try {
            $handoverToType = $data['handover_to_type'] ?? 'cashier';
            $handoverToId = $data['handover_to_id'] ?? $data['next_cashier_id'] ?? null;

            Log::info('Recording handover', [
                'shift_id' => $shift->id,
                'handover_to_type' => $handoverToType,
                'handover_to_id' => $handoverToId,
            ]);

            // Update shift with handover details
            if ($handoverToType === 'cashier' && isset($data['next_cashier_id'])) {
                $shift->update([
                    'next_cashier_id' => $data['next_cashier_id'],
                ]);
            }

            // Calculate variance using the correct formula:
            // Variance = Total Sales - (Cash Collected + Card Payments + Delivery Apps)
            // Note: handover_amount is the cash being handed over, not used for variance calculation
            $variance = $shift->calculateVariance();
            $expectedBalance = $shift->total_sales;

            $shift->update([
                'closing_balance' => $data['handover_amount'],
                'handover_notes' => $data['handover_notes'] ?? null,
                'handed_over_at' => now(),
                'expected_balance' => $expectedBalance,
                'variance' => $variance,
            ]);

            // Handle variance files upload
            $varianceFiles = null;
            if (!empty($data['variance_files'])) {
                $varianceFiles = $this->uploadVarianceFiles($data['variance_files'], $shift->id);
            }

            // Create CashierShiftHandover record
            $handoverData = [
                'cashier_shift_id' => $shift->id,
                'handover_to_id' => $handoverToId,
                'handover_to_type' => $handoverToType,
                'handover_amount' => $data['handover_amount'],
                'variance_amount' => $variance,
                'variance_reason' => $data['variance_reason'] ?? null,
                'variance_files' => $varianceFiles,
                'handover_notes' => $data['handover_notes'] ?? null,
                'handover_date' => now()->toDateString(), // Use actual handover date, not shift date
                'handover_time' => now(),
                'status' => 'pending',
            ];

            Log::info('Creating CashierShiftHandover', [
                'handover_data' => $handoverData,
            ]);

            $handover = CashierShiftHandover::create($handoverData);

            // Create or update ShiftHandoverStatus for approval tracking
            // Use updateOrCreate to avoid duplicate entry errors
            ShiftHandoverStatus::updateOrCreate(
                ['cashier_shift_id' => $shift->id],
                [
                    'status' => HandoverStatus::PENDING,
                    'manager_approval_status' => 'pending',
                ]
            );

            // Record history
            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_RECORDED->value,
                null,
                [
                    'handover_to_type' => $handoverToType,
                    'handover_to_id' => $handoverToId,
                    'next_cashier_id' => $data['next_cashier_id'] ?? null,
                    'handover_amount' => $data['handover_amount'],
                    'variance' => $variance,
                ]
            );

            DB::commit();

            // Clear cache for branch manager shift if handover is to branch manager
            if ($handoverToType === 'branch_manager' && $handoverToId) {
                try {
                    $branchManagerShift = \Modules\Shift\Models\BranchManagerShift::where('branch_manager_id', $handoverToId)
                        ->whereDate('shift_date', $handover->handover_date)
                        ->first();

                    if ($branchManagerShift) {
                        // Clear cache using cache tags if available
                        if (config('cache.default') === 'redis') {
                            $shiftTag = "shift:{$branchManagerShift->id}:{$branchManagerShift->shift_date->format('Y-m-d')}";
                            try {
                                \Illuminate\Support\Facades\Cache::tags([$shiftTag])->flush();
                            } catch (\Exception $e) {
                                // Fallback: clear specific cache keys
                                \Illuminate\Support\Facades\Cache::forget("shift:{$branchManagerShift->id}:handovers:to_manager");
                            }
                        } else {
                            // Clear specific cache keys
                            \Illuminate\Support\Facades\Cache::forget("shift:{$branchManagerShift->id}:handovers:to_manager");
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to clear cache after handover', [
                        'error' => $e->getMessage(),
                        'handover_id' => $handover->id,
                    ]);
                }
            }

            Log::info('Handover recorded successfully', [
                'shift_id' => $shift->id,
                'handover_id' => $handover->id,
                'handover_to_type' => $handoverToType,
                'handover_to_id' => $handoverToId,
            ]);

            return $shift->fresh(['nextCashier', 'handoverStatus']);
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
     * Approve a handover (by Branch Manager)
     *
     * Business Rule: Changes status from Pending → Approved
     *
     * @param CashierShift $shift
     * @param string $reviewerId
     * @param string $reviewerType
     * @param string|null $managerComment
     * @return CashierShift
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
            // Use updateOrCreate to avoid duplicate entry errors
            if (!$shift->handoverStatus) {
                ShiftHandoverStatus::updateOrCreate(
                    ['cashier_shift_id' => $shift->id],
                    [
                        'status' => HandoverStatus::PENDING,
                        'manager_approval_status' => 'pending',
                    ]
                );
                $shift->refresh();
            }

            // Check if can be approved
            if (!$shift->handoverStatus->canBeApproved()) {
                throw new \Exception('Handover cannot be approved in current state. Status: ' . $shift->handoverStatus->manager_approval_status);
            }

            // Approve using model method
            $shift->handoverStatus->approve($reviewerId, $reviewerType, $managerComment);

            // Mark shift as completed
            $shift->update([
                'status' => ShiftStatus::COMPLETED,
            ]);

            // Update CashierShiftHandover status
            $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->first();
            if ($handover) {
                $handover->update([
                    'status' => 'approved',
                    'approved_by_id' => $reviewerId,
                    'approved_by_type' => $reviewerType,
                    'approved_at' => now(),
                ]);

                // Fire event for personal ledger transaction creation
                if ($handover->handover_to_type === 'branch_manager') {
                    event(new \Modules\Custody\Events\HandoverApproved($handover));
                }
            }

            // Try auto handover to next shift
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
     * Reject a handover (by Branch Manager)
     *
     * Business Rules:
     * - First rejection: Cashier can edit and resubmit
     * - Second rejection: Status permanently changes to 'rejected_final'
     *
     * @param CashierShift $shift
     * @param string $reviewerId
     * @param string $reviewerType
     * @param string $reason
     * @param array $files
     * @param string|null $comment
     * @return array
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

            // Check if can be rejected
            if (!$handoverStatus->canBeRejected()) {
                throw new \Exception('This handover cannot be rejected. Current status: ' . $handoverStatus->manager_approval_status);
            }

            // Upload rejection files
            $uploadedFiles = [];
            foreach ($files as $file) {
                $filename = 'rejection_' . $shift->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('handover_rejections', $filename, 'public');
                $uploadedFiles[] = $path;
            }

            // Use model's reject method
            $result = $handoverStatus->reject($reviewerId, $reviewerType, $reason, $uploadedFiles, $comment);

            // Update CashierShiftHandover status
            CashierShiftHandover::where('cashier_shift_id', $shift->id)
                ->update([
                    'status' => $result['is_final_rejection'] ? 'rejected_final' : 'rejected',
                    'rejection_reason' => $reason,
                    'rejection_count' => $result['rejection_count'],
                ]);

            // Record history
            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_REJECTED->value,
                ['status' => $handoverStatus->status->value],
                [
                    'status' => HandoverStatus::REJECTED->value,
                    'manager_approval_status' => $result['is_final_rejection'] ? 'rejected_final' : 'rejected',
                    'reviewed_by_id' => $reviewerId,
                    'reviewed_by_type' => $reviewerType,
                    'rejection_reason' => $reason,
                    'rejection_count' => $result['rejection_count'],
                    'is_final_rejection' => $result['is_final_rejection'],
                ]
            );

            DB::commit();

            return [
                'shift_id' => $shift->id,
                'handover_status' => $result['is_final_rejection'] ? 'rejected_final' : 'rejected',
                'rejection_count' => $result['rejection_count'],
                'is_final_rejection' => $result['is_final_rejection'],
                'can_cashier_edit' => $result['can_cashier_edit'],
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
     * Edit handover after rejection (by Cashier)
     *
     * Business Rule: If cashier edits rejected handover → Can be re-approved or rejected again
     *
     * @param CashierShift $shift
     * @param array $data
     * @return CashierShift
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

            // Recalculate variance using the correct formula:
            // Variance = Total Sales - (Cash Collected + Card Payments + Delivery Apps)
            $variance = $shift->calculateVariance();
            $expectedBalance = $shift->total_sales;
            $shift->update([
                'expected_balance' => $expectedBalance,
                'variance' => $variance,
            ]);

            // Update CashierShiftHandover
            CashierShiftHandover::where('cashier_shift_id', $shift->id)
                ->update([
                    'handover_amount' => $data['handover_amount'],
                    'variance_amount' => $variance,
                    'handover_notes' => $data['handover_notes'] ?? null,
                    'status' => 'pending',
                ]);

            // Mark as edited using model method
            $handoverStatus->markAsEdited();

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
     * Accept a handover (by receiving Cashier)
     *
     * Used when next cashier accepts the handover from previous cashier
     *
     * @param CashierShift $shift
     * @param string $cashierId
     * @param string|null $comment
     * @return void
     */
    public function acceptHandoverByCashier(
        CashierShift $shift,
        string $cashierId,
        ?string $comment = null
    ): void {
        DB::beginTransaction();
        try {
            // Verify this cashier is the next cashier
            if ($shift->next_cashier_id !== $cashierId) {
                throw new \Exception('You are not authorized to accept this handover.');
            }

            $shift->handoverStatus->update([
                'status' => HandoverStatus::ACCEPTED,
                'reviewed_by_id' => $cashierId,
                'reviewed_by_type' => \Modules\Cashier\Models\Cashier::class,
                'manager_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            // Update next cashier's shift with opening balance
            $nextShift = CashierShift::where('cashier_id', $cashierId)
                ->where('shift_date', $shift->shift_date)
                ->where('status', ShiftStatus::NOT_STARTED)
                ->first();

            if ($nextShift) {
                $nextShift->update([
                    'opening_balance' => $shift->closing_balance,
                ]);
            }

            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_ACCEPTED->value,
                ['status' => HandoverStatus::PENDING->value],
                [
                    'status' => HandoverStatus::ACCEPTED->value,
                    'reviewed_by_id' => $cashierId,
                    'reviewed_by_type' => 'cashier',
                ]
            );

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Reject a handover (by receiving Cashier)
     *
     * @param CashierShift $shift
     * @param string $cashierId
     * @param string $reason
     * @param array $files
     * @return void
     */
    public function rejectHandoverByCashier(
        CashierShift $shift,
        string $cashierId,
        string $reason,
        array $files = []
    ): void {
        DB::beginTransaction();
        try {
            // Verify this cashier is the next cashier
            if ($shift->next_cashier_id !== $cashierId) {
                throw new \Exception('You are not authorized to reject this handover.');
            }

            // Upload rejection files
            $uploadedFiles = [];
            foreach ($files as $file) {
                $filename = 'cashier_rejection_' . $shift->id . '_' . time() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('handover_rejections', $filename, 'public');
                $uploadedFiles[] = $path;
            }

            $shift->handoverStatus->update([
                'status' => HandoverStatus::REJECTED,
                'reviewed_by_id' => $cashierId,
                'reviewed_by_type' => \Modules\Cashier\Models\Cashier::class,
                'rejection_reason' => $reason,
                'rejection_files' => $uploadedFiles ?: null,
                'reviewed_at' => now(),
            ]);

            $shift->recordHistory(
                'handover_rejected_by_cashier',
                ['status' => HandoverStatus::PENDING->value],
                [
                    'status' => HandoverStatus::REJECTED->value,
                    'reviewed_by_id' => $cashierId,
                    'reviewed_by_type' => 'cashier',
                    'rejection_reason' => $reason,
                ]
            );

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Automatically hand over to the next scheduled shift
     *
     * Business Rule: System automatically ensures handover from Cashier 1 to Cashier 2
     * when shifts are consecutive (e.g., 9 AM – 6 PM → 6 PM – 12 AM)
     *
     * @param CashierShift $endedShift
     * @return CashierShift|null
     */
    public function autoHandover(CashierShift $endedShift): ?CashierShift
    {
        try {
            // Skip if already handed over
            if ($endedShift->handoverStatus?->status === HandoverStatus::ACCEPTED) {
                Log::warning('Auto handover skipped: shift already handed over', ['shift_id' => $endedShift->id]);
                return null;
            }

            // Find the next shift (same date, same branch, starts after this shift ends)
            $nextShift = CashierShift::where('shift_date', $endedShift->shift_date)
                ->whereHas('shift', function ($q) use ($endedShift) {
                    $q->where('branch_id', $endedShift->shift->branch_id)
                        ->where('start_time', '>=', $endedShift->shift->end_time);
                })
                ->where('status', ShiftStatus::NOT_STARTED)
                ->orderBy('shift_id')
                ->first();

            if (!$nextShift) {
                Log::info("No next shift found for auto handover", ['shift_id' => $endedShift->id]);
                return null;
            }

            // Record automatic handover
            $handoverAmount = $endedShift->closing_balance ?? $endedShift->total_sales ?? 0;

            $recorded = $this->recordHandover($endedShift, [
                'handover_to_type' => 'cashier',
                'handover_to_id' => $nextShift->cashier_id,
                'next_cashier_id' => $nextShift->cashier_id,
                'handover_amount' => $handoverAmount,
                'handover_notes' => 'Auto handover executed by system',
            ]);

            // Update next shift's opening balance
            $nextShift->update([
                'opening_balance' => $handoverAmount,
            ]);

            Log::info('Auto handover completed successfully', [
                'shift_id' => $endedShift->id,
                'next_shift_id' => $nextShift->id,
                'next_cashier_id' => $nextShift->cashier_id,
                'handover_amount' => $handoverAmount,
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
     * Get handover summaries with statistics
     *
     * @param array $filters
     * @return array
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
            $pendingHandovers = $shifts->filter(fn($s) => $s->handoverStatus?->manager_approval_status === 'pending')->count();
            $acceptedHandovers = $shifts->filter(fn($s) => $s->handoverStatus?->manager_approval_status === 'approved')->count();
            $rejectedHandovers = $shifts->filter(fn($s) => in_array($s->handoverStatus?->manager_approval_status, ['rejected', 'rejected_final']))->count();
            $finalRejectedHandovers = $shifts->filter(fn($s) => $s->handoverStatus?->manager_approval_status === 'rejected_final')->count();

            // Variance statistics
            $totalVariance = $shifts->sum('variance');
            $avgVariance = $totalHandovers > 0 ? $shifts->avg('variance') : 0;

            $overages = $shifts->filter(fn($s) => $s->variance > 0);
            $shortages = $shifts->filter(fn($s) => $s->variance < 0);

            $totalOverage = $overages->sum('variance');
            $totalShortage = abs($shortages->sum('variance'));

            // Financial amounts
            $totalHandoverAmount = $shifts->sum('closing_balance');
            $totalExpectedAmount = $shifts->sum('expected_balance');

            // Recent handovers (last 10)
            $recentHandovers = $shifts->sortByDesc('handed_over_at')
                ->take(10)
                ->map(function ($shift) {
                    return [
                        'shift_id' => $shift->id,
                        'shift_date' => $shift->shift_date->format('Y-m-d'),
                        'cashier_name' => $shift->cashier?->name,
                        'next_cashier_name' => $shift->nextCashier?->name,
                        'handover_amount' => (float) $shift->closing_balance,
                        'variance' => (float) $shift->variance,
                        'variance_type' => $shift->variance > 0 ? 'Over' : ($shift->variance < 0 ? 'Short' : 'None'),
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
                    'acceptance_rate' => $totalHandovers > 0 ? round(($acceptedHandovers / $totalHandovers) * 100, 2) : 0,
                    'rejection_rate' => $totalHandovers > 0 ? round(($rejectedHandovers / $totalHandovers) * 100, 2) : 0,
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

    /**
     * Upload variance files
     *
     * @param array $files
     * @param string $shiftId
     * @return array
     */
    private function uploadVarianceFiles(array $files, string $shiftId): array
    {
        $uploadedFiles = [];

        foreach ($files as $file) {
            $filename = 'variance_' . $shiftId . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('variance/files', $filename, 'public');
            $uploadedFiles[] = $path;
        }

        return $uploadedFiles;
    }
}
