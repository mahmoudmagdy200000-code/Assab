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
     * Approve a shift handover
     */
    public function approveHandover(
        CashierShift $shift,
        string $reviewerId,
        string $reviewerType, // Full class name like 'App\Models\User'
        ?string $managerComment = null
    ): CashierShift {
        DB::beginTransaction();
        try {
            // Ensure handoverStatus exists
            if (!$shift->handoverStatus) {
                ShiftHandoverStatus::create([
                    'cashier_shift_id' => $shift->id,
                    'status' => HandoverStatus::PENDING,
                ]);
                $shift->refresh();
            }

            // Update handover status with polymorphic relationship
            $shift->handoverStatus->update([
                'status' => HandoverStatus::ACCEPTED,
                'reviewed_by_id' => $reviewerId,      // Use reviewed_by_id
                'reviewed_by_type' => $reviewerType,  // Use reviewed_by_type
                'manager_comment' => $managerComment,
                'reviewed_at' => Carbon::now(),
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
     * Reject a handover
     */
    public function rejectHandover(
        CashierShift $shift,
        string $reviewerId,
        string $reviewerType,
        string $reason,
        array $files = [],
        ?string $comment = null
    ): void {
        DB::beginTransaction();
        try {
            $uploadedFiles = [];
            foreach ($files as $file) {
                $filename = 'rejection_' . $shift->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('handover_rejections', $filename, 'public');
                $uploadedFiles[] = $path;
            }

            $shift->handoverStatus->update([
                'status' => HandoverStatus::REJECTED,
                'reviewed_by_id' => $reviewerId,
                'reviewed_by_type' => $reviewerType,
                'rejection_reason' => $reason,
                'rejection_files' => $uploadedFiles ? json_encode($uploadedFiles) : null,
                'manager_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_REJECTED->value,
                ['status' => HandoverStatus::PENDING->value],
                [
                    'status' => HandoverStatus::REJECTED->value,
                    'reviewed_by_id' => $reviewerId,
                    'reviewed_by_type' => $reviewerType,
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
     * Automatically record a handover to the next shift
     */
    public function autoHandover(CashierShift $endedShift): ?CashierShift
    {
        try {
            // Prevent duplicate auto handover
            if ($endedShift->handoverStatus?->status === HandoverStatus::ACCEPTED) {
                Log::warning('Auto handover skipped: shift already handed over', ['shift_id' => $endedShift->id]);
                return null;
            }

            // Find next shift (based on time order, not just ID)
            $nextShift = CashierShift::where('shift_date', $endedShift->shift_date)
                ->whereHas(
                    'shift',
                    fn($q) =>
                    $q->where('start_time', '>', $endedShift->shift->end_time)
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
     *
     * @param array $filters Optional filters: branch_id, date_from, date_to, status, cashier_id
     * @return array Summary statistics for handovers
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
                    $q->where('status', $filters['status']);
                });
            }

            if (!empty($filters['cashier_id'])) {
                $query->where('cashier_id', $filters['cashier_id']);
            }

            $shifts = $query->get();

            // Calculate statistics
            $totalHandovers = $shifts->count();
            $pendingHandovers = $shifts->filter(
                fn($s) =>
                $s->handoverStatus?->status === HandoverStatus::PENDING
            )->count();

            $acceptedHandovers = $shifts->filter(
                fn($s) =>
                $s->handoverStatus?->status === HandoverStatus::ACCEPTED
            )->count();

            $rejectedHandovers = $shifts->filter(
                fn($s) =>
                $s->handoverStatus?->status === HandoverStatus::REJECTED
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
                        'status' => $shift->handoverStatus?->status->value,
                        'handed_over_at' => $shift->handed_over_at?->format('Y-m-d H:i:s'),
                        'branch_name' => $shift->shift?->branch?->name,
                    ];
                })
                ->values();

            // Status breakdown by branch (if not filtered by branch)
            $branchBreakdown = [];
            if (empty($filters['branch_id'])) {
                $branchBreakdown = $shifts->groupBy('shift.branch.name')
                    ->map(function ($branchShifts, $branchName) {
                        return [
                            'branch_name' => $branchName,
                            'total' => $branchShifts->count(),
                            'pending' => $branchShifts->filter(
                                fn($s) =>
                                $s->handoverStatus?->status === HandoverStatus::PENDING
                            )->count(),
                            'accepted' => $branchShifts->filter(
                                fn($s) =>
                                $s->handoverStatus?->status === HandoverStatus::ACCEPTED
                            )->count(),
                            'rejected' => $branchShifts->filter(
                                fn($s) =>
                                $s->handoverStatus?->status === HandoverStatus::REJECTED
                            )->count(),
                            'total_variance' => (float) $branchShifts->sum('variance'),
                        ];
                    })
                    ->values();
            }

            // Cashier performance summary
            $cashierPerformance = $shifts->groupBy('cashier_id')
                ->map(function ($cashierShifts) {
                    $cashier = $cashierShifts->first()->cashier;
                    return [
                        'cashier_id' => $cashier->id,
                        'cashier_name' => $cashier->name,
                        'total_handovers' => $cashierShifts->count(),
                        'accepted' => $cashierShifts->filter(
                            fn($s) =>
                            $s->handoverStatus?->status === HandoverStatus::ACCEPTED
                        )->count(),
                        'rejected' => $cashierShifts->filter(
                            fn($s) =>
                            $s->handoverStatus?->status === HandoverStatus::REJECTED
                        )->count(),
                        'pending' => $cashierShifts->filter(
                            fn($s) =>
                            $s->handoverStatus?->status === HandoverStatus::PENDING
                        )->count(),
                        'total_variance' => (float) $cashierShifts->sum('variance'),
                        'avg_variance' => (float) $cashierShifts->avg('variance'),
                        'accuracy_rate' => $cashierShifts->count() > 0
                            ? round(($cashierShifts->filter(fn($s) => abs($s->variance) < 10)->count() / $cashierShifts->count()) * 100, 2)
                            : 0,
                    ];
                })
                ->sortByDesc('total_handovers')
                ->take(10)
                ->values();

            return [
                'overview' => [
                    'total_handovers' => $totalHandovers,
                    'pending' => $pendingHandovers,
                    'accepted' => $acceptedHandovers,
                    'rejected' => $rejectedHandovers,
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
                'branch_breakdown' => $branchBreakdown,
                'cashier_performance' => $cashierPerformance,
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




// this is a test case for the auto handover with notification feature

// <?php


// namespace Modules\Shift\Services;

// use Carbon\Carbon;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// use Modules\Shift\Models\CashierShift;
// use Modules\Shift\Models\ShiftHandoverStatus;
// use Modules\Shift\Enums\HandoverStatus;
// use Modules\Shift\Enums\ShiftHistoryAction;
// use Modules\Shift\Enums\ShiftStatus;

// class HandoverService
// {
//     /**
//      * Approve a shift handover
//      */
//     public function approveHandover(
//         CashierShift $shift,
//         int $reviewerId,
//         string $reviewerType,
        // ?string $managerComment = null
//     ): CashierShift {
//         DB::beginTransaction();
//         try {
//             if (!$shift->handoverStatus) {
//                 ShiftHandoverStatus::create([
//                     'cashier_shift_id' => $shift->id,
//                     'status' => HandoverStatus::PENDING,
//                 ]);
//                 $shift->refresh();
//             }

//             $shift->handoverStatus->update([
//                 'status' => HandoverStatus::ACCEPTED,
//                 'reviewed_by' => $reviewerId,
//                 'reviewer_type' => $reviewerType,
//                 'manager_comment' => $managerComment,
//                 'reviewed_at' => Carbon::now(),
//             ]);

//             $shift->update([
//                 'status' => ShiftStatus::COMPLETED,
//             ]);

//             try {
//                 $this->autoHandover($shift);
//             } catch (\Throwable $ex) {
//                 Log::warning('Auto handover failed but approval succeeded', [
//                     'shift_id' => $shift->id,
//                     'error' => $ex->getMessage(),
//                 ]);
//             }

//             $shift->recordHistory(
//                 ShiftHistoryAction::HANDOVER_APPROVED->value,
//                 ['status' => HandoverStatus::PENDING->value],
//                 [
//                     'status' => HandoverStatus::ACCEPTED->value,
//                     'reviewed_by' => $reviewerId,
//                     'reviewer_type' => $reviewerType,
//                 ],
//                 $managerComment
//             );

//             $shift->load([
//                 'handoverStatus.reviewedBy',
//                 'nextCashier',
//                 'cashier',
//                 'shift',
//                 'salesBreakdown.aggregator',
//                 'varianceDetails.responsibleCashier',
//             ]);

//             DB::commit();
//             return $shift;
//         } catch (\Exception $e) {
//             DB::rollBack();
//             Log::error('Failed to approve handover', [
//                 'shift_id' => $shift->id,
//                 'error' => $e->getMessage(),
//                 'trace' => $e->getTraceAsString(),
//             ]);
//             throw $e;
//         }
//     }

//     /**
//      * Record a handover
//      */
//     public function recordHandover(CashierShift $shift, array $data): CashierShift
//     {
//         DB::beginTransaction();
//         try {
//             Log::info('Recording handover', [
//                 'shift_id' => $shift->id,
//                 'next_cashier_id' => $data['next_cashier_id'],
//             ]);

//             $shift->update([
//                 'next_cashier_id' => $data['next_cashier_id'],
//                 'closing_balance' => $data['handover_amount'],
//                 'handover_notes' => $data['handover_notes'] ?? null,
//                 'handed_over_at' => now(),
//             ]);

//             $expectedBalance = $shift->total_sales;
//             $variance = $expectedBalance - $data['handover_amount'];

//             $shift->update([
//                 'expected_balance' => $expectedBalance,
//                 'variance' => $variance,
//             ]);

//             ShiftHandoverStatus::create([
//                 'cashier_shift_id' => $shift->id,
//                 'status' => HandoverStatus::PENDING,
//             ]);

//             $shift->recordHistory(
//                 ShiftHistoryAction::HANDOVER_RECORDED->value,
//                 null,
//                 [
//                     'next_cashier_id' => $data['next_cashier_id'],
//                     'handover_amount' => $data['handover_amount'],
//                     'variance' => $variance,
//                 ]
//             );

//             DB::commit();

//             Log::info('Handover recorded successfully', [
//                 'shift_id' => $shift->id,
//                 'next_cashier_id' => $shift->next_cashier_id,
//             ]);

//             return $shift->fresh(['nextCashier']);
//         } catch (\Exception $e) {
//             DB::rollBack();
//             Log::error('Failed to record handover', [
//                 'shift_id' => $shift->id,
//                 'error' => $e->getMessage(),
//                 'trace' => $e->getTraceAsString(),
//             ]);
//             throw $e;
//         }
//     }

//     /**
//      * Accept a handover
//      */
//     public function acceptHandover(CashierShift $shift, int $reviewerId, ?string $comment = null): void
//     {
//         DB::beginTransaction();
//         try {
//             $shift->handoverStatus->update([
//                 'status' => HandoverStatus::ACCEPTED,
//                 'reviewed_by' => $reviewerId,
//                 'manager_comment' => $comment,
//                 'reviewed_at' => now(),
//             ]);

//             $shift->recordHistory(
//                 ShiftHistoryAction::HANDOVER_ACCEPTED->value,
//                 ['status' => HandoverStatus::PENDING->value],
//                 [
//                     'status' => HandoverStatus::ACCEPTED->value,
//                     'reviewed_by' => $reviewerId,
//                 ]
//             );

//             DB::commit();
//         } catch (\Exception $e) {
//             DB::rollBack();
//             throw $e;
//         }
//     }

//     /**
//      * Reject a handover
//      */
//     public function rejectHandover(
//         CashierShift $shift,
//         int $reviewerId,
//         string $reason,
//         array $files = [],
//         ?string $comment = null
//     ): void {
//         DB::beginTransaction();
//         try {
//             $uploadedFiles = [];
//             foreach ($files as $file) {
//                 $filename = 'rejection_' . $shift->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
//                 $path = $file->storeAs('handover_rejections', $filename, 'public');
//                 $uploadedFiles[] = $path;
//             }

//             $shift->handoverStatus->update([
//                 'status' => HandoverStatus::REJECTED,
//                 'reviewed_by' => $reviewerId,
//                 'rejection_reason' => $reason,
//                 'rejection_files' => $uploadedFiles ? json_encode($uploadedFiles) : null,
//                 'manager_comment' => $comment,
//                 'reviewed_at' => now(),
//             ]);

//             $shift->recordHistory(
//                 ShiftHistoryAction::HANDOVER_REJECTED->value,
//                 ['status' => HandoverStatus::PENDING->value],
//                 [
//                     'status' => HandoverStatus::REJECTED->value,
//                     'reviewed_by' => $reviewerId,
//                     'rejection_reason' => $reason,
//                 ]
//             );

//             DB::commit();
//         } catch (\Exception $e) {
//             DB::rollBack();
//             throw $e;
//         }
//     }

//     /**
//      * Automatically record a handover to the next shift
//      */
//     public function autoHandover(CashierShift $endedShift): ?CashierShift
//     {
//         try {
//             if ($endedShift->handoverStatus?->status === HandoverStatus::ACCEPTED) {
//                 Log::warning('Auto handover skipped: shift already handed over', ['shift_id' => $endedShift->id]);
//                 return null;
//             }

//             $nextShift = CashierShift::where('shift_date', $endedShift->shift_date)
//                 ->whereHas('shift', fn($q) =>
//                     $q->where('start_time', '>', $endedShift->shift->end_time)
//                 )
//                 ->orderBy('shift_id')
//                 ->first();

//             if (!$nextShift) {
//                 Log::info("No next shift found for auto handover", ['shift_id' => $endedShift->id]);
//                 return null;
//             }

//             $handoverAmount = $endedShift->closing_balance ?? $endedShift->total_sales ?? 0;

//             $recorded = $this->recordHandover($endedShift, [
//                 'next_cashier_id' => $nextShift->cashier_id,
//                 'handover_amount' => $handoverAmount,
//                 'handover_notes' => 'Auto handover executed by system',
//             ]);

//             Log::info('Auto handover completed successfully', [
//                 'shift_id' => $endedShift->id,
//                 'next_shift_id' => $nextShift->id,
//                 'next_cashier_id' => $nextShift->cashier_id,
//             ]);

//             return $recorded;
//         } catch (\Exception $e) {
//             Log::error('Auto handover failed', [
//                 'shift_id' => $endedShift->id,
//                 'error' => $e->getMessage(),
//             ]);
//             return null;
//         }
//     }
// }
