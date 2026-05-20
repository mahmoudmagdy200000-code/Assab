<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Shift\Models\CashierShiftHandover;

/**
 * BranchManagerShiftResource
 *
 * Resource for Branch Manager's workday management data
 * Matches Section 3.1.3 requirements
 */
class BranchManagerShiftResource extends JsonResource
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const STATUS_NOT_SUBMITTED = 'Not Submitted';

    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'shift_date' => $this->shift_date?->format('Y-m-d'),
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),

            // Section A: Shift Overview
            'shift_overview' => $this->getShiftOverview(),

            // Section B: Shift Details
            'shift_details' => $this->getShiftDetails(),

            // Section C: Handoffs Summary
            'handoffs_summary' => $this->getHandoffsSummary(),

            // Section D: Final Handover Info
            'final_handover' => $this->getFinalHandoverInfo(),

            // Section E: Daily Close Summary
            'daily_close' => $this->getDailyCloseSummary(),

            // Financial Summary
            'financial_summary' => $this->getFinancialSummary(),

            // Available Actions
            'available_actions' => $this->getAvailableActions(),

            // Timestamps
            'timestamps' => [
                'actual_start_time' => $this->actual_start_time?->format(self::DATETIME_FORMAT),
                'actual_end_time' => $this->actual_end_time?->format(self::DATETIME_FORMAT),
                'daily_report_submitted_at' => $this->daily_report_submitted_at?->format(self::DATETIME_FORMAT),
                'reopened_at' => $this->reopened_at?->format(self::DATETIME_FORMAT),
                'approved_at' => $this->approved_at?->format(self::DATETIME_FORMAT),
                'archived_at' => $this->archived_at?->format(self::DATETIME_FORMAT),
            ],
        ];
    }

    /**
     * Get shift overview (Section A)
     */
    private function getShiftOverview(): array
    {
        $progress = $this->calculateProgress();

        return [
            'title' => 'Branch Manager Shift - '.($this->shift_date?->format('d M Y') ?? 'Today'),
            'description' => 'Managing daily operations and cashier handovers',
            'status' => $this->getStatusLabel(),
            'start_time' => $this->actual_start_time?->format('H:i') ?? '09:00',
            'end_time' => $this->actual_end_time?->format('H:i') ?? '17:00',
            'elapsed_hours' => $progress['elapsed_hours'],
            'progress_percentage' => $progress['progress_percentage'],
            'can_start' => $this->status === 'not_started' && $this->shift_date?->isToday(),
            'can_end' => $this->canEnd(),
        ];
    }

    /**
     * Get shift details (Section B)
     */
    private function getShiftDetails(): array
    {
        return [
            'assigned_to' => 'Me (Branch Manager)',
            'assigned_by' => 'Brand Owner',
            'store_branch' => $this->branch?->name ?? 'N/A',
            'branch_id' => $this->branch_id,
            'start_time' => $this->actual_start_time?->format('H:i') ?? 'Not started',
            'end_time' => $this->actual_end_time?->format('H:i') ?? 'In progress',
            'final_approval_by' => $this->approvedBy?->name ?? 'Pending (Sales Team Dashboard)',
            'manager_name' => $this->branchManager?->name ?? 'N/A',
            'manager_id' => $this->branch_manager_id,
        ];
    }

    /**
     * Get handoffs summary (Section C)
     * Uses pre-attached handoffs_summary when set by BranchManagerShiftService::attachHandoffsAndFinancialSummariesForCollection to avoid N+1.
     */
    private function getHandoffsSummary(): array
    {
        $precomputed = $this->resource->getAttribute('handoffs_summary');
        if (is_array($precomputed)) {
            return $precomputed;
        }

        $handovers = CashierShiftHandover::where('handover_to_type', 'branch_manager')
            ->where('handover_to_id', $this->branch_manager_id)
            ->whereHas('cashierShift', function ($query) {
                $query->whereDate('shift_date', $this->shift_date)
                    ->whereHas('shift', function ($q) {
                        $q->where('branch_id', $this->branch_id);
                    });
            })
            ->get();

        return [
            'total_handovers' => $handovers->count(),
            'approved' => $handovers->where('status', 'approved')->count(),
            'pending' => $handovers->where('status', 'pending')->count(),
            'rejected' => $handovers->whereIn('status', ['rejected', 'rejected_final'])->count(),
            'rejected_final' => $handovers->where('status', 'rejected_final')->count(),
            'total_amount' => (float) $handovers->where('status', 'approved')->sum('handover_amount'),
            'total_variance' => (float) $handovers->sum('variance_amount'),
            'all_received' => $handovers->count() > 0 && $handovers->where('status', 'pending')->count() === 0,
        ];
    }

    /**
     * Get final handover info (Section D)
     * Exact format as per requirements:
     * - Handover Amount
     * - Status: Completed, Not Submitted, Pending
     * - Handover From
     * - Handover To
     * - Handover Date
     * - Handover Time
     * - Current Time (Today or Yesterday)
     */
    private function getFinalHandoverInfo(): ?array
    {
        // Determine status based on requirements
        $status = self::STATUS_NOT_SUBMITTED;
        if ($this->handover_status === 'completed' || $this->handover_status === 'approved') {
            $status = 'Completed';
        } elseif ($this->handover_status === 'pending') {
            $status = 'Pending';
        }

        // Get current time based on handover_timing
        $currentTime = $this->handover_timing === 'yesterday'
            ? now()->subDay()->format(self::DATETIME_FORMAT)
            : now()->format(self::DATETIME_FORMAT);

        $expectedBalance = (float) ($this->total_sales ?? 0);
        $closingBalance = (float) ($this->handover_amount ?? $this->closing_balance ?? 0);
        $variance = $expectedBalance - $closingBalance;
        $finalCashCollected = $closingBalance;

        $lastDepositDateRaw = $this->handover_date ?? $this->actual_end_time ?? now();
        $lastDepositDate = $lastDepositDateRaw instanceof \DateTimeInterface
            ? $lastDepositDateRaw->format('Y-m-d')
            : (is_string($lastDepositDateRaw) ? $lastDepositDateRaw : now()->format('Y-m-d'));

        $lastDepositTimeRaw = $this->handover_time ?? $this->actual_end_time ?? now();
        $lastDepositTime = $lastDepositTimeRaw instanceof \DateTimeInterface
            ? $lastDepositTimeRaw->format('H:i:s')
            : now()->format('H:i:s');

        $varianceType = $variance > 0 ? 'Over' : ($variance < 0 ? 'Short' : 'None');

        return [
            'handover_amount' => (float) ($this->handover_amount ?? 0),
            'status' => $status,
            'status_options' => ['Completed', self::STATUS_NOT_SUBMITTED, 'Pending'],
            'handover_from' => $this->branchManager?->name ?? 'N/A',
            'handover_to' => $this->nextManager?->name ?? 'Not specified',
            'handover_date' => $this->handover_date?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'handover_time' => $this->handover_time?->format('H:i:s') ?? now()->format('H:i:s'),
            'current_time' => $currentTime,
            'current_time_setting' => $this->handover_timing ?? 'today',
            'handover_notes' => $this->handover_notes,
            'opening_balance' => (float) ($this->opening_balance ?? 0),
            'closing_balance' => $closingBalance,
            'expected_balance' => $expectedBalance,
            'variance' => $variance,
            'variance_type' => $varianceType,
            'petty_cash' => (float) $finalCashCollected,
            'last_deposit' => (float) $finalCashCollected,
            'last_deposit_date' => $lastDepositDate,
            'last_deposit_time' => $lastDepositTime,
        ];
    }

    /**
     * Get daily close summary (Section E)
     * Note: The actual cashier breakdown and totals are returned from prepareDailyCloseSummary()
     * This method only returns submission status and metadata
     */
    private function getDailyCloseSummary(): array
    {
        return [
            'is_submitted' => (bool) $this->daily_report_submitted,
            'submitted_at' => $this->daily_report_submitted_at?->format(self::DATETIME_FORMAT),
            'notes' => $this->daily_report_notes,
            'can_reopen' => $this->can_reopen && $this->daily_report_submitted,
            'reopened_at' => $this->reopened_at?->format(self::DATETIME_FORMAT),
            'reopen_reason' => $this->reopen_reason,
            'is_archived' => ! is_null($this->archived_at),
            'archived_at' => $this->archived_at?->format(self::DATETIME_FORMAT),
            'approved_by' => $this->approvedBy?->name,
            'approved_at' => $this->approved_at?->format(self::DATETIME_FORMAT),
        ];
    }

    /**
     * Get financial summary
     * Uses pre-attached financial_summary when set by BranchManagerShiftService::attachHandoffsAndFinancialSummariesForCollection to avoid N+1.
     */
    private function getFinancialSummary(): array
    {
        $precomputed = $this->resource->getAttribute('financial_summary');
        if (is_array($precomputed)) {
            return $precomputed;
        }

        if ($this->total_sales > 0 || $this->cash_collected > 0 || $this->card_payments > 0) {
            return [
                'total_sales' => (float) ($this->total_sales ?? 0),
                'net_sales' => (float) ($this->net_sales ?? 0),
                'vat_amount' => (float) ($this->vat_amount ?? 0),
                'cash_collected' => (float) ($this->cash_collected ?? 0),
                'card_payments' => (float) ($this->card_payments ?? 0),
                'aggregator_payments' => (float) ($this->aggregator_payments ?? 0),
                'total_variance' => (float) ($this->variance ?? 0),
            ];
        }

        $handovers = CashierShiftHandover::where('handover_to_type', 'branch_manager')
            ->where('handover_to_id', $this->branch_manager_id)
            ->whereHas('cashierShift', function ($query) {
                $query->whereDate('shift_date', $this->shift_date)
                    ->whereHas('shift', function ($q) {
                        $q->where('branch_id', $this->branch_id);
                    });
            })
            ->with(['cashierShift.salesBreakdown.aggregator'])
            ->get();

        $totalSales = 0;
        $cashCollected = 0;
        $cardPayments = 0;
        $aggregatorPayments = 0;
        $totalVariance = 0;

        foreach ($handovers as $handover) {
            $cashierShift = $handover->cashierShift;
            $totalSales += $cashierShift->total_sales ?? 0;
            $cashCollected += $cashierShift->cash_collected ?? 0;
            $cardPayments += $cashierShift->card_payments ?? 0;
            $aggregatorPayments += $cashierShift->salesBreakdown?->sum('amount') ?? 0;
            $totalVariance += $handover->variance_amount ?? 0;
        }

        $vatAmount = $totalSales * 0.15;
        $netSales = $totalSales - $vatAmount;

        return [
            'total_sales' => (float) $totalSales,
            'net_sales' => (float) $netSales,
            'vat_amount' => (float) $vatAmount,
            'cash_collected' => (float) $cashCollected,
            'card_payments' => (float) $cardPayments,
            'aggregator_payments' => (float) $aggregatorPayments,
            'total_variance' => (float) $totalVariance,
        ];
    }

    /**
     * Get available actions
     */
    private function getAvailableActions(): array
    {
        return [
            'can_start' => $this->status === 'not_started' && $this->shift_date?->isToday(),
            'can_end' => $this->canEnd(),
            'can_submit_daily_report' => $this->status === 'completed' && ! $this->daily_report_submitted,
            'can_reopen' => $this->can_reopen && $this->daily_report_submitted && $this->shift_date?->isToday(),
            'can_approve_handovers' => $this->status === 'in_progress',
            'can_reject_handovers' => $this->status === 'in_progress',
        ];
    }

    /**
     * Calculate progress
     */
    private function calculateProgress(): array
    {
        $defaultShiftHours = 8;
        $elapsedHours = 0;
        $progressPercentage = 0;

        if ($this->status === 'in_progress' && $this->actual_start_time) {
            $expectedEndTime = $this->actual_end_time ?? $this->actual_start_time->copy()->addHours($defaultShiftHours);
            $totalMinutes = $this->actual_start_time->diffInMinutes($expectedEndTime);
            $elapsedMinutes = now()->diffInMinutes($this->actual_start_time);

            $elapsedHours = round($elapsedMinutes / 60, 2);
            $progressPercentage = $totalMinutes > 0 ? min(($elapsedMinutes / $totalMinutes) * 100, 100) : 0;
        } elseif ($this->status === 'completed') {
            $progressPercentage = 100;
            if ($this->actual_start_time && $this->actual_end_time) {
                $elapsedHours = round($this->actual_start_time->diffInMinutes($this->actual_end_time) / 60, 2);
            }
        }

        return [
            'elapsed_hours' => $elapsedHours,
            'progress_percentage' => round($progressPercentage, 1),
        ];
    }

    /**
     * Get status label
     */
    private function getStatusLabel(): string
    {
        return match ($this->status) {
            'not_started' => 'Not Started',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            default => 'Unknown',
        };
    }
}
