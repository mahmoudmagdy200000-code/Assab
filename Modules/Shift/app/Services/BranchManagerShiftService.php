<?php

namespace Modules\Shift\Services;
use Illuminate\Support\Facades\DB;
use Modules\Shift\Models\{BranchManagerShift, CashierShift};
use Modules\Shift\Enums\ShiftStatus;
use Carbon\Carbon;

class BranchManagerShiftService
{
    /**
     * Get or create manager shift for today
     */
    public function getOrCreateTodayShift(string $managerId, string $branchId): BranchManagerShift
    {
        return BranchManagerShift::firstOrCreate([
            'branch_manager_id' => $managerId,
            'shift_date' => today(),
        ], [
            'branch_id' => $branchId,
            'status' => 'not_started',
        ]);
    }

    /**
     * Start manager shift
     */
    public function startShift(BranchManagerShift $managerShift): BranchManagerShift
    {
        DB::beginTransaction();
        try {
            if (!$managerShift->canStart()) {
                throw new \Exception('Cannot start this shift');
            }

            $managerShift->startShift();
            $managerShift->updateStatistics();

            DB::commit();
            return $managerShift->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * End manager shift
     */
    public function endShift(BranchManagerShift $managerShift, array $data = []): BranchManagerShift
    {
        DB::beginTransaction();
        try {
            if (!$managerShift->canEnd()) {
                throw new \Exception('Cannot end this shift');
            }

            // Aggregate all cashier shifts data
            $managerShift->aggregateSalesData();
            $managerShift->updateStatistics();

            // End the shift with optional manual data
            $managerShift->endShift($data);

            // Calculate variance
            if ($managerShift->hasVariance()) {
                $managerShift->update([
                    'variance' => $managerShift->calculateVariance(),
                    'expected_balance' => $managerShift->total_sales,
                ]);
            }

            DB::commit();
            return $managerShift->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get shift progress details
     */
    public function getShiftProgress(BranchManagerShift $managerShift): array
    {
        $totalMinutes = $managerShift->actual_start_time
            ? Carbon::parse($managerShift->actual_start_time)->diffInMinutes(now())
            : 0;

        $progress = $managerShift->getProgressPercentage();

        return [
            'title' => "Branch Manager Shift - {$managerShift->shift_date->format('d M Y')}",
            'description' => "Managing {$managerShift->total_cashier_shifts} cashier shifts",
            'status' => $managerShift->status,
            'start_time' => $managerShift->actual_start_time?->format('H:i'),
            'end_time' => $managerShift->actual_end_time?->format('H:i'),
            'elapsed_minutes' => $totalMinutes,
            'progress_percentage' => round($progress, 2),
            'statistics' => [
                'total_shifts' => $managerShift->total_cashier_shifts,
                'completed_shifts' => $managerShift->completed_cashier_shifts,
                'pending_shifts' => $managerShift->pending_cashier_shifts,
            ],
        ];
    }

    /**
     * Get shift summary
     */
    public function getShiftSummary(BranchManagerShift $managerShift): array
    {
        return [
            'financial_summary' => [
                'total_sales' => (float) $managerShift->total_sales,
                'net_sales' => (float) $managerShift->net_sales,
                'vat_amount' => (float) $managerShift->vat_amount,
                'sales_breakdown' => [
                    'cash_collected' => (float) $managerShift->cash_collected,
                    'card_payments' => (float) $managerShift->card_payments,
                    'delivery_apps' => (float) $managerShift->aggregator_payments,
                ],
            ],
            'shift_statistics' => [
                'total_cashier_shifts' => $managerShift->total_cashier_shifts,
                'completed_shifts' => $managerShift->completed_cashier_shifts,
                'pending_shifts' => $managerShift->pending_cashier_shifts,
                'completion_rate' => $managerShift->total_cashier_shifts > 0
                    ? round(($managerShift->completed_cashier_shifts / $managerShift->total_cashier_shifts) * 100, 2)
                    : 0,
            ],
            'handover_details' => [
                'opening_balance' => (float) $managerShift->opening_balance,
                'closing_balance' => (float) $managerShift->closing_balance,
                'expected_balance' => (float) $managerShift->expected_balance,
                'variance' => (float) $managerShift->variance,
                'variance_type' => $managerShift->variance > 0 ? 'Over' : ($managerShift->variance < 0 ? 'Short' : 'None'),
            ],
        ];
    }

    /**
     * Record handover to next manager
     */
    public function recordHandover(
        BranchManagerShift $managerShift,
        string $nextManagerId,
        float $handoverAmount,
        ?string $notes = null
    ): BranchManagerShift {
        DB::beginTransaction();
        try {
            $managerShift->update([
                'next_manager_id' => $nextManagerId,
                'closing_balance' => $handoverAmount,
                'handed_over_at' => now(),
                'handover_notes' => $notes,
                'expected_balance' => $managerShift->total_sales,
                'variance' => $managerShift->total_sales - $handoverAmount,
            ]);

            // Create opening balance for next day's shift
            $nextDayShift = BranchManagerShift::firstOrCreate([
                'branch_manager_id' => $nextManagerId,
                'shift_date' => $managerShift->shift_date->addDay(),
            ], [
                'branch_id' => $managerShift->branch_id,
                'status' => 'not_started',
                'opening_balance' => $handoverAmount,
            ]);

            DB::commit();
            return $managerShift->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get cashier shifts breakdown
     */
    public function getCashierShiftsBreakdown(BranchManagerShift $managerShift): array
    {
        $cashierShifts = $managerShift->cashierShifts()
            ->with(['cashier', 'shift', 'handoverStatus'])
            ->get();

        return [
            'by_status' => [
                'not_started' => $cashierShifts->where('status', ShiftStatus::NOT_STARTED)->count(),
                'in_progress' => $cashierShifts->where('status', ShiftStatus::IN_PROGRESS)->count(),
                'completed' => $cashierShifts->where('status', ShiftStatus::COMPLETED)->count(),
                'reassigned' => $cashierShifts->where('status', ShiftStatus::REASSIGNED)->count(),
            ],
            'by_cashier' => $cashierShifts->groupBy('cashier_id')->map(function ($shifts, $cashierId) {
                $cashier = $shifts->first()->cashier;
                return [
                    'cashier_id' => $cashierId,
                    'cashier_name' => $cashier->name,
                    'total_shifts' => $shifts->count(),
                    'completed' => $shifts->where('status', ShiftStatus::COMPLETED)->count(),
                    'total_sales' => $shifts->sum('total_sales'),
                ];
            })->values(),
            'handover_status' => [
                'pending' => $cashierShifts->filter(fn($s) =>
                    $s->handoverStatus && $s->handoverStatus->status->value === 'pending'
                )->count(),
                'accepted' => $cashierShifts->filter(fn($s) =>
                    $s->handoverStatus && $s->handoverStatus->status->value === 'accepted'
                )->count(),
                'rejected' => $cashierShifts->filter(fn($s) =>
                    $s->handoverStatus && $s->handoverStatus->status->value === 'rejected'
                )->count(),
            ],
        ];
    }
}
