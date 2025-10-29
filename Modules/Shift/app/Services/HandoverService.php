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
        int $reviewerId,
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
                ]);
                $shift->refresh();
            }

            // Update handover status
            $shift->handoverStatus->update([
                'status' => HandoverStatus::ACCEPTED,
                'reviewed_by' => $reviewerId,
                'reviewer_type' => $reviewerType,
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
                    'reviewed_by' => $reviewerId,
                    'reviewer_type' => $reviewerType,
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
     * Record a handover
     */
    public function recordHandover(CashierShift $shift, array $data): CashierShift
    {
        DB::beginTransaction();
        try {
            Log::info('Recording handover', [
                'shift_id' => $shift->id,
                'next_cashier_id' => $data['next_cashier_id'],
            ]);

            // Update shift with handover details
            $shift->update([
                'next_cashier_id' => $data['next_cashier_id'],
                'closing_balance' => $data['handover_amount'],
                'handover_notes' => $data['handover_notes'] ?? null,
                'handed_over_at' => now(),
            ]);

            // Calculate variance
            $expectedBalance = $shift->total_sales;
            $variance = $expectedBalance - $data['handover_amount'];

            $shift->update([
                'expected_balance' => $expectedBalance,
                'variance' => $variance,
            ]);

            // Create handover status record
            ShiftHandoverStatus::create([
                'cashier_shift_id' => $shift->id,
                'status' => HandoverStatus::PENDING,
            ]);

            // Record history (using enum)
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
     * Accept a handover
     */
    public function acceptHandover(CashierShift $shift, int $reviewerId, ?string $comment = null): void
    {
        DB::beginTransaction();
        try {
            $shift->handoverStatus->update([
                'status' => HandoverStatus::ACCEPTED,
                'reviewed_by' => $reviewerId,
                'manager_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_ACCEPTED->value,
                ['status' => HandoverStatus::PENDING->value],
                [
                    'status' => HandoverStatus::ACCEPTED->value,
                    'reviewed_by' => $reviewerId,
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
        int $reviewerId,
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
                'reviewed_by' => $reviewerId,
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
                    'reviewed_by' => $reviewerId,
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

