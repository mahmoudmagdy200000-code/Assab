<?php

namespace Modules\Shift\Services;

use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HandoverService
{
    public function __construct(
        private ShiftNotificationService $notificationService
    ) {}

    public function recordHandover(CashierShift $shift, array $data): ShiftHandoverStatus
    {
        DB::beginTransaction();
        try {
            // Update shift with handover info
            $shift->update([
                'status' => ShiftStatus::COMPLETED,
                'next_cashier_id' => $data['next_cashier_id'],
                'handed_over_at' => now(),
                'handover_notes' => $data['handover_notes'] ?? null,
                'closing_balance' => $data['handover_amount'],
            ]);

            // Calculate variance
            $variance = $this->calculateHandoverVariance($shift, $data['handover_amount']);

            // Create handover status
            $handoverStatus = ShiftHandoverStatus::create([
                'cashier_shift_id' => $shift->id,
                'status' => HandoverStatus::PENDING,
                'reviewed_by' => null,
                'rejection_reason' => null,
                'rejection_files' => null,
                'manager_comment' => null,
                'reviewed_at' => null,
            ]);

            // Send notification to next cashier
            $this->notificationService->notifyHandoverPending($shift);

            DB::commit();
            return $handoverStatus;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function approveHandover(CashierShift $shift, int $reviewerId, string $reviewerType): void
    {
        DB::beginTransaction();
        try {
            $shift->handoverStatus->update([
                'status' => HandoverStatus::ACCEPTED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            // Update next shift opening balance
            $this->updateNextShiftOpeningBalance($shift);

            // Send notification
            $this->notificationService->notifyHandoverApproved($shift);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function rejectHandover(CashierShift $shift, array $data): void
    {
        DB::beginTransaction();
        try {
            // Upload rejection files if provided
            $rejectionFiles = null;
            if (!empty($data['rejection_files'])) {
                $rejectionFiles = $this->uploadRejectionFiles($data['rejection_files'], $shift->id);
            }

            $shift->handoverStatus->update([
                'status' => HandoverStatus::REJECTED,
                'reviewed_by' => $data['reviewed_by'],
                'rejection_reason' => $data['rejection_reason'],
                'rejection_files' => $rejectionFiles,
                'manager_comment' => $data['manager_comment'] ?? null,
                'reviewed_at' => now(),
            ]);

            // Send notification
            $this->notificationService->notifyHandoverRejected($shift);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function calculateHandoverVariance(CashierShift $shift, float $handoverAmount): float
    {
        return $shift->total_sales - $handoverAmount;
    }

    private function updateNextShiftOpeningBalance(CashierShift $shift): void
    {
        if ($shift->next_cashier_id) {
            $nextShift = CashierShift::where('cashier_id', $shift->next_cashier_id)
                ->where('shift_date', $shift->shift_date)
                ->where('status', ShiftStatus::NOT_STARTED)
                ->first();

            if ($nextShift) {
                $nextShift->update([
                    'opening_balance' => $shift->closing_balance,
                ]);
            }
        }
    }

    private function uploadRejectionFiles(array $files, int $shiftId): string
    {
        $uploadedFiles = [];

        foreach ($files as $file) {
            $filename = 'rejection_' . $shiftId . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('handover/rejections', $filename, 'public');
            $uploadedFiles[] = $path;
        }

        return json_encode($uploadedFiles);
    }
}
