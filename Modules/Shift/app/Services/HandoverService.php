<?php

namespace Modules\Shift\Services;

use Carbon\Carbon;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Enums\HandoverStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Shift\Enums\ShiftHistoryAction;
use Modules\Shift\Enums\ShiftStatus;

class HandoverService
{


    /**
     * Approve a shift handover
     *
     * @param CashierShift $shift
     * @param int $reviewerId
     * @param string $reviewerType
     * @param string|null $managerComment
     * @return CashierShift
     */
    public function approveHandover(
        CashierShift $shift,
        int $reviewerId,
        string $reviewerType,
        ?string $managerComment = null
    ): CashierShift {
        DB::beginTransaction();
        try {
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
                'status' => ShiftStatus::COMPLETED // Use the enum value instead of string
            ]);

            // Record history
            // Record history using the enum
            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_APPROVED->value,
                [
                    'status' => HandoverStatus::PENDING->value,
                ],
                [
                    'status' => HandoverStatus::ACCEPTED->value,
                    'reviewed_by' => $reviewerId,
                    'reviewer_type' => $reviewerType,
                ],
                $managerComment
            );

            // Load necessary relationships
            $shift->load([
                'handoverStatus.reviewedBy',
                'nextCashier',
                'cashier',
                'shift',
                'salesBreakdown.aggregator',
                'varianceDetails.responsibleCashier'
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

            // Record history
            $shift->recordHistory('handed_over', null, [
                'next_cashier_id' => $data['next_cashier_id'],
                'handover_amount' => $data['handover_amount'],
                'variance' => $variance,
            ]);

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

            $shift->recordHistory('handover_accepted', [
                'status' => HandoverStatus::PENDING->value,
            ], [
                'status' => HandoverStatus::ACCEPTED->value,
                'reviewed_by' => $reviewerId,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function rejectHandover(
        CashierShift $shift,
        int $reviewerId,
        string $reason,
        array $files = [],
        ?string $comment = null
    ): void {
        DB::beginTransaction();
        try {
            // Handle file uploads
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
                'rejection_files' => !empty($uploadedFiles) ? json_encode($uploadedFiles) : null,
                'manager_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            $shift->recordHistory('handover_rejected', [
                'status' => HandoverStatus::PENDING->value,
            ], [
                'status' => HandoverStatus::REJECTED->value,
                'reviewed_by' => $reviewerId,
                'rejection_reason' => $reason,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
