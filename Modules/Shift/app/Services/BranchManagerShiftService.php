<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shift\Models\{BranchManagerShift, CashierShift};
use Modules\Shift\Enums\ShiftStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

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
                'pending' => $cashierShifts->filter(
                    fn($s) =>
                    $s->handoverStatus && $s->handoverStatus->status->value === 'pending'
                )->count(),
                'accepted' => $cashierShifts->filter(
                    fn($s) =>
                    $s->handoverStatus && $s->handoverStatus->status->value === 'accepted'
                )->count(),
                'rejected' => $cashierShifts->filter(
                    fn($s) =>
                    $s->handoverStatus && $s->handoverStatus->status->value === 'rejected'
                )->count(),
            ],
        ];
    }


    /**
     * Get final daily close summary for manager shift - معدل
     */
    public function getFinalDailyCloseSummary(BranchManagerShift $managerShift): array
    {
        try {
            $cashierShifts = $managerShift->cashierShifts()
                ->with(['cashier', 'shift', 'handoverStatus', 'salesBreakdown.aggregator'])
                ->where('status', ShiftStatus::COMPLETED)
                ->get();

            // Calculate payment breakdown by cashier
            $paymentBreakdown = $cashierShifts->map(function ($cashierShift) {
                $deliveryApps = $cashierShift->salesBreakdown->groupBy('aggregator.name')
                    ->map(function ($breakdowns, $aggregatorName) {
                        return [
                            'name' => $aggregatorName,
                            'amount' => (float) $breakdowns->sum('amount')
                        ];
                    })->values();

                $totalDeliveryApps = $deliveryApps->sum('amount');

                return [
                    'cashier_shift_id' => $cashierShift->id,
                    'cashier_name' => $cashierShift->cashier->name,
                    'shift_time' => $cashierShift->shift->name,
                    'cash_collected' => (float) $cashierShift->cash_collected,
                    'card_payments' => (float) $cashierShift->card_payments,
                    'delivery_apps' => $deliveryApps->toArray(),
                    'total_delivery_apps' => (float) $totalDeliveryApps,
                    'variance' => (float) $cashierShift->variance,
                    'total_sales' => (float) $cashierShift->total_sales,
                    'handover_status' => $cashierShift->handoverStatus?->status->value,
                    'handover_amount' => (float) $cashierShift->closing_balance,
                    'handed_over_at' => $cashierShift->handed_over_at?->format('Y-m-d H:i:s'),
                ];
            });

            // Calculate totals
            $totals = [
                'cash_collected' => $paymentBreakdown->sum('cash_collected'),
                'card_payments' => $paymentBreakdown->sum('card_payments'),
                'total_delivery_apps' => $paymentBreakdown->sum('total_delivery_apps'),
                'total_variance' => $paymentBreakdown->sum('variance'),
                'total_sales' => $paymentBreakdown->sum('total_sales'),
            ];

            // Aggregate delivery apps across all cashiers
            $aggregatedDeliveryApps = $cashierShifts->flatMap(function ($shift) {
                return $shift->salesBreakdown->map(function ($breakdown) {
                    return [
                        'aggregator_name' => $breakdown->aggregator->name,
                        'amount' => (float) $breakdown->amount
                    ];
                });
            })->groupBy('aggregator_name')->map(function ($items, $aggregatorName) {
                return [
                    'name' => $aggregatorName,
                    'amount' => (float) $items->sum('amount')
                ];
            })->values();

            return [
                'shift_info' => [
                    'shift_id' => $managerShift->id,
                    'shift_date' => $managerShift->shift_date->format('Y-m-d'),
                    'branch_name' => $managerShift->branch->name,
                    'manager_name' => $managerShift->branchManager->name,
                    'status' => $managerShift->status,
                    'start_time' => $managerShift->actual_start_time?->format('H:i'),
                    'end_time' => $managerShift->actual_end_time?->format('H:i'),
                ],
                'payment_breakdown' => $paymentBreakdown->values(),
                'totals' => $totals,
                'aggregated_delivery_apps' => $aggregatedDeliveryApps,
                'handover_summary' => [
                    'total_handovers' => $cashierShifts->filter(fn($s) => $s->handoverStatus)->count(),
                    'pending_handovers' => $cashierShifts->filter(
                        fn($s) =>
                        $s->handoverStatus && $s->handoverStatus->status->value === 'pending'
                    )->count(),
                    'accepted_handovers' => $cashierShifts->filter(
                        fn($s) =>
                        $s->handoverStatus && $s->handoverStatus->status->value === 'accepted'
                    )->count(),
                    'rejected_handovers' => $cashierShifts->filter(
                        fn($s) =>
                        $s->handoverStatus && $s->handoverStatus->status->value === 'rejected'
                    )->count(),
                ],
                'manager_financial_summary' => [
                    'opening_balance' => (float) $managerShift->opening_balance,
                    'total_sales' => (float) $managerShift->total_sales,
                    'expected_balance' => (float) $managerShift->expected_balance,
                    'closing_balance' => (float) $managerShift->closing_balance,
                    'variance' => (float) $managerShift->variance,
                    'variance_type' => $managerShift->variance > 0 ? 'Over' : ($managerShift->variance < 0 ? 'Short' : 'None'),
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Get final daily close summary error', [
                'shift_id' => $managerShift->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update final daily close with manual adjustments
     */
    public function updateFinalDailyClose(BranchManagerShift $managerShift, array $data): BranchManagerShift
    {
        DB::beginTransaction();
        try {
            $updates = [];

            // Allow manual adjustment of closing balance
            if (isset($data['closing_balance'])) {
                $updates['closing_balance'] = $data['closing_balance'];
                $updates['variance'] = $managerShift->total_sales - $data['closing_balance'];
            }

            // Allow manual notes
            if (isset($data['daily_report_notes'])) {
                $updates['daily_report_notes'] = $data['daily_report_notes'];
            }

            // Allow manual adjustment of expected balance
            if (isset($data['expected_balance'])) {
                $updates['expected_balance'] = $data['expected_balance'];
            }

            // Update the shift
            if (!empty($updates)) {
                $managerShift->update($updates);
            }

            // Update individual cashier shifts if provided
            if (isset($data['cashier_adjustments']) && is_array($data['cashier_adjustments'])) {
                foreach ($data['cashier_adjustments'] as $adjustment) {
                    if (isset($adjustment['cashier_shift_id']) && isset($adjustment['closing_balance'])) {
                        $cashierShift = CashierShift::find($adjustment['cashier_shift_id']);
                        if ($cashierShift && $cashierShift->branch_manager_shift_id === $managerShift->id) {
                            $cashierShift->update([
                                'closing_balance' => $adjustment['closing_balance'],
                                'variance' => $cashierShift->total_sales - $adjustment['closing_balance']
                            ]);
                        }
                    }
                }
            }

            DB::commit();
            return $managerShift->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Submit final daily report
     */
    public function submitFinalDailyReport(BranchManagerShift $managerShift, array $data = []): BranchManagerShift
    {
        DB::beginTransaction();
        try {
            // Apply any final adjustments
            if (!empty($data)) {
                $managerShift = $this->updateFinalDailyClose($managerShift, $data);
            }

            // Mark as final submitted
            $managerShift->update([
                'daily_report_submitted' => true,
                'daily_report_submitted_at' => now(),
                'daily_report_notes' => $data['final_notes'] ?? $managerShift->daily_report_notes,
            ]);

            // Record history
            $managerShift->recordHistory(
                'daily_report_submitted',
                null,
                [
                    'closing_balance' => $managerShift->closing_balance,
                    'variance' => $managerShift->variance,
                    'total_sales' => $managerShift->total_sales,
                ],
                $data['final_notes'] ?? null
            );

            DB::commit();
            return $managerShift->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
