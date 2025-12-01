<?php

namespace Modules\Shift\Services;

use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\BranchManagers\Models\BranchManager;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BranchManagerShiftService
{
    /**
     * Auto-archive completed shifts after 7 days
     */
    public function autoArchiveOldShifts(): void
    {
        $cutoffDate = Carbon::now()->subDays(7);

        BranchManagerShift::where('status', 'completed')
            ->where('daily_report_submitted', true)
            ->where('archived_at', null)
            ->whereDate('shift_date', '<', $cutoffDate)
            ->update(['archived_at' => now()]);
    }

    /**
     * Get available next managers for handover
     */
    public function getAvailableNextManagers(BranchManagerShift $currentShift): array
    {
        return BranchManager::where('branch_id', $currentShift->branch_id)
            ->where('id', '!=', $currentShift->branch_manager_id)
            ->where('is_active', true)
            ->get()
            ->map(fn($manager) => [
                'id' => $manager->id,
                'name' => $manager->name,
                'email' => $manager->email,
            ])
            ->toArray();
    }

    /**
     * Calculate variance details for a handover
     */
    public function calculateVarianceDetails(CashierShiftHandover $handover): array
    {
        $cashierShift = $handover->cashierShift;

        return [
            'total_sales' => (float) $cashierShift->total_sales,
            'handover_amount' => (float) $handover->handover_amount,
            'variance_amount' => (float) $handover->variance_amount,
            'variance_type' => $handover->variance_amount > 0 ? 'Over' : ($handover->variance_amount < 0 ? 'Short' : 'None'),
            'reason' => $handover->variance_reason,
            'attached_files' => $handover->variance_files ?? [],
            'cashier_details' => [
                'id' => $cashierShift->cashier_id,
                'name' => $cashierShift->cashier->name,
                'shift_time' => $cashierShift->shift->name,
            ],
        ];
    }

    /**
     * Update shift statistics after handover approval/rejection
     */
    public function updateShiftStatistics(BranchManagerShift $shift): void
    {
        $handovers = $shift->cashierHandovers;

        $statistics = [
            'total_cashier_shifts' => $handovers->count(),
            'approved_handovers' => $handovers->where('status', 'approved')->count(),
            'pending_handovers' => $handovers->where('status', 'pending')->count(),
            'rejected_handovers' => $handovers->whereIn('status', ['rejected', 'rejected_final'])->count(),
        ];

        $shift->update($statistics);
    }

    /**
     * Validate if shift can be ended
     */
    public function validateShiftEnd(BranchManagerShift $shift): array
    {
        $errors = [];

        if ($shift->status !== 'in_progress') {
            $errors[] = 'Shift is not in progress';
        }

        $pendingHandovers = $shift->cashierHandovers()->where('status', 'pending')->count();
        if ($pendingHandovers > 0) {
            $errors[] = "There are {$pendingHandovers} pending cashier handovers";
        }

        return [
            'can_end' => empty($errors),
            'errors' => $errors,
            'pending_count' => $pendingHandovers,
        ];
    }

    /**
     * Process handover from cashier to manager
     */
    public function processCashierHandover(CashierShiftHandover $handover): void
    {
        DB::transaction(function () use ($handover) {
            // Update cashier shift status
            $handover->cashierShift()->update([
                'status' => 'completed',
                'handed_over_at' => now(),
            ]);

            // Notify manager about new handover
            $this->notifyManagerAboutHandover($handover);
        });
    }

    private function notifyManagerAboutHandover(CashierShiftHandover $handover): void
    {
        // Implementation for sending notification to manager
        // This could be email, push notification, or in-app notification
    }
}
