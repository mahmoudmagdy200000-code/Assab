<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shift\Models\{BranchManagerShift, CashierShift, ShiftHandoverStatus as CashierShiftHandover};
use Modules\Shift\Enums\ShiftStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\BranchManagers\Models\BranchManager;

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

            // Check all handoffs are approved
            $pendingHandoffs = $this->getPendingHandoffs($managerShift);
            if ($pendingHandoffs > 0) {
                throw new \Exception("Cannot end shift. {$pendingHandoffs} cashier handoffs pending approval.");
            }

            // Aggregate all cashier shifts data
            $managerShift->aggregateSalesData();
            $managerShift->updateStatistics();

            // End the shift
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

        // Get expected shift end time from shift configuration
        $expectedEndTime = $managerShift->shift?->end_time;
        $canEnd = $managerShift->status === 'in_progress';

        // Check if current time has passed end time (Section A requirement)
        if ($expectedEndTime && $managerShift->actual_start_time) {
            $endDateTime = Carbon::parse($managerShift->shift_date->format('Y-m-d') . ' ' . $expectedEndTime);
            $canEnd = $canEnd && now()->greaterThanOrEqualTo($endDateTime);
        }

        return [
            'title' => "Branch Manager Shift - {$managerShift->shift_date->format('d M Y')}",
            'description' => "Managing {$managerShift->total_cashier_shifts} cashier shifts",
            'status' => $managerShift->status,
            'start_time' => $managerShift->actual_start_time?->format('H:i'),
            'end_time' => $managerShift->actual_end_time?->format('H:i'),
            'expected_end_time' => $expectedEndTime,
            'elapsed_minutes' => $totalMinutes,
            'progress_percentage' => round($progress, 2),
            'can_end_shift' => $canEnd,
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
                    fn($s) => $s->handoverStatus && $s->handoverStatus->manager_approval_status === 'pending'
                )->count(),
                'approved' => $cashierShifts->filter(
                    fn($s) => $s->handoverStatus && $s->handoverStatus->manager_approval_status === 'approved'
                )->count(),
                'rejected' => $cashierShifts->filter(
                    fn($s) => $s->handoverStatus && in_array($s->handoverStatus->manager_approval_status, ['rejected', 'rejected_final'])
                )->count(),
            ],
        ];
    }

    /**
     * Get handoffs received from cashiers - Section C
     */
    public function getHandoffsReceived(BranchManagerShift $managerShift): array
    {
        $cashierShifts = $managerShift->cashierShifts()
            ->with(['cashier', 'shift', 'handoverStatus', 'salesBreakdown.aggregator'])
            ->where('status', ShiftStatus::COMPLETED)
            ->get();

        return $cashierShifts->map(function ($cashierShift) {
            $handover = $cashierShift->handoverStatus;

            return [
                'handover_id' => $handover?->id,
                'cashier_shift_id' => $cashierShift->id,
                'cashier_name' => $cashierShift->cashier->name,
                'shift_time' => $cashierShift->shift->name,
                'handover_amount' => (float) $cashierShift->closing_balance,
                'total_sales' => (float) $cashierShift->total_sales,
                'variance' => (float) $cashierShift->variance,
                'variance_type' => $cashierShift->variance > 0 ? 'Over' : ($cashierShift->variance < 0 ? 'Short' : 'None'),
                'variance_reason' => $handover?->variance_reason,
                'attached_files' => $handover?->variance_files ? json_decode($handover->variance_files) : [],
                'manager_approval_status' => $handover?->manager_approval_status ?? 'pending',
                'rejection_reason' => $handover?->rejection_reason,
                'rejection_count' => $handover?->rejection_count ?? 0,
                'was_edited_after_rejection' => $handover?->was_edited_after_rejection ?? false,
                'handed_over_at' => $cashierShift->handed_over_at?->format('Y-m-d H:i:s'),
                'approved_at' => $handover?->approved_at?->format('Y-m-d H:i:s'),
                'can_approve' => $handover && $handover->manager_approval_status === 'pending',
                'can_reject' => $handover && in_array($handover->manager_approval_status, ['pending', 'rejected']) && $handover->rejection_count < 2,
            ];
        })->toArray();
    }

    /**
     * Approve handoff - Section C
     */
    public function approveHandoff(CashierShiftHandover $handover, string $managerId): array
    {
        DB::beginTransaction();
        try {
            if ($handover->manager_approval_status !== 'pending') {
                throw new \Exception('This handoff is not pending approval');
            }

            $handover->update([
                'manager_approval_status' => 'approved',
                'approved_by' => $managerId,
                'approved_at' => now(),
            ]);

            DB::commit();

            return [
                'handover_id' => $handover->id,
                'status' => 'approved',
                'approved_at' => $handover->approved_at->format('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Reject handoff - Section C (with 2-rejection rule)
     */
    public function rejectHandoff(CashierShiftHandover $handover, string $managerId, string $reason): array
    {
        DB::beginTransaction();
        try {
            if ($handover->rejection_count >= 2) {
                throw new \Exception('This handoff has already been rejected twice');
            }

            $rejectionCount = $handover->rejection_count + 1;
            $isFinalRejection = $rejectionCount >= 2;

            $updateData = [
                'manager_approval_status' => $isFinalRejection ? 'rejected_final' : 'rejected',
                'rejection_reason' => $reason,
                'rejection_count' => $rejectionCount,
                'approved_by' => $managerId,
            ];

            if ($rejectionCount === 1) {
                $updateData['first_rejected_at'] = now();
            } elseif ($rejectionCount === 2) {
                $updateData['second_rejected_at'] = now();
            }

            $handover->update($updateData);

            DB::commit();

            return [
                'handover_id' => $handover->id,
                'status' => $handover->manager_approval_status,
                'rejection_count' => $rejectionCount,
                'is_final_rejection' => $isFinalRejection,
                'rejection_reason' => $reason,
                'rejected_at' => now()->format('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get pending handoffs count
     */
    public function getPendingHandoffs(BranchManagerShift $managerShift): int
    {
        return $managerShift->cashierShifts()
            ->whereHas('handoverStatus', function ($q) {
                $q->where('manager_approval_status', 'pending');
            })
            ->count();
    }

    /**
     * Get final daily close summary - Section E
     */
    public function getFinalDailyCloseSummary(BranchManagerShift $managerShift): array
    {
        try {
            $cashierShifts = $managerShift->cashierShifts()
                ->with(['cashier', 'shift', 'handoverStatus', 'salesBreakdown.aggregator'])
                ->where('status', ShiftStatus::COMPLETED)
                ->get();

            // Payment breakdown by cashier
            $paymentBreakdown = $cashierShifts->map(function ($cashierShift) {
                $deliveryApps = $cashierShift->salesBreakdown->groupBy('aggregator.name')
                    ->map(function ($breakdowns, $aggregatorName) {
                        return [
                            'name' => $aggregatorName,
                            'amount' => (float) $breakdowns->sum('amount')
                        ];
                    })->values();

                return [
                    'cashier_shift_id' => $cashierShift->id,
                    'cashier_name' => $cashierShift->cashier->name,
                    'shift_time' => $cashierShift->shift->name,
                    'cash_collected' => (float) $cashierShift->cash_collected,
                    'card_payments' => (float) $cashierShift->card_payments,
                    'delivery_apps' => $deliveryApps->toArray(),
                    'total_delivery_apps' => (float) $deliveryApps->sum('amount'),
                    'variance' => (float) $cashierShift->variance,
                    'total_sales' => (float) $cashierShift->total_sales,
                    'handover_status' => $cashierShift->handoverStatus?->manager_approval_status,
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

            // Aggregate delivery apps
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
                'payment_breakdown_by_cashier' => $paymentBreakdown->values(),
                'totals' => $totals,
                'aggregated_delivery_apps' => $aggregatedDeliveryApps,
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
     * Update final daily close
     */
    public function updateFinalDailyClose(BranchManagerShift $managerShift, array $data): BranchManagerShift
    {
        DB::beginTransaction();
        try {
            $updates = [];

            if (isset($data['closing_balance'])) {
                $updates['closing_balance'] = $data['closing_balance'];
                $updates['variance'] = $managerShift->total_sales - $data['closing_balance'];
            }

            if (isset($data['daily_report_notes'])) {
                $updates['daily_report_notes'] = $data['daily_report_notes'];
            }

            if (isset($data['expected_balance'])) {
                $updates['expected_balance'] = $data['expected_balance'];
            }

            if (!empty($updates)) {
                $managerShift->update($updates);
            }

            // Update cashier shifts if provided
            if (isset($data['cashier_adjustments']) && is_array($data['cashier_adjustments'])) {
                foreach ($data['cashier_adjustments'] as $adjustment) {
                    if (isset($adjustment['cashier_shift_id']) && isset($adjustment['closing_balance'])) {
                        $cashierShift = CashierShift::find($adjustment['cashier_shift_id']);
                        if ($cashierShift) {
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
            if (!empty($data)) {
                $managerShift = $this->updateFinalDailyClose($managerShift, $data);
            }

            $managerShift->update([
                'daily_report_submitted' => true,
                'daily_report_submitted_at' => now(),
                'daily_report_notes' => $data['final_notes'] ?? $managerShift->daily_report_notes,
                'can_reopen' => true, // Allow reopening on same day
            ]);

            DB::commit();
            return $managerShift->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Record manager handover to next manager - Section D
     */
    public function recordManagerHandover(
        BranchManagerShift $managerShift,
        string $nextManagerId,
        float $handoverAmount,
        ?string $notes = null,
        string $timing = 'today'
    ): BranchManagerShift {
        DB::beginTransaction();
        try {
            // Set handover timestamp based on timing
            $handoverTime = $timing === 'yesterday'
                ? now()->subDay()->endOfDay()
                : now();

            $managerShift->update([
                'next_manager_id' => $nextManagerId,
                'handover_from' => $managerShift->branch_manager_id,  // ✅ Add this
                'handover_to' => $nextManagerId,                      // ✅ Add this
                'handover_amount' => $handoverAmount,                 // ✅ Add this
                'closing_balance' => $handoverAmount,
                'handed_over_at' => $handoverTime,
                'handover_notes' => $notes,
                'handover_timing' => $timing,                         // ✅ Add this
                'handover_status' => 'pending',                       // ✅ Add this
                'expected_balance' => $managerShift->total_sales,
                'variance' => $managerShift->total_sales - $handoverAmount,
            ]);

            // Create opening balance for next manager's shift
            $nextShiftDate = $timing === 'yesterday'
                ? $managerShift->shift_date
                : $managerShift->shift_date->addDay();

            BranchManagerShift::firstOrCreate([
                'branch_manager_id' => $nextManagerId,
                'shift_date' => $nextShiftDate,
            ], [
                'branch_id' => $managerShift->branch_id,
                'status' => 'not_started',
                'opening_balance' => $handoverAmount,
            ]);

            DB::commit();
            return $managerShift->fresh(['nextManager']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
    public function reopenShift(BranchManagerShift $managerShift, string $reason): BranchManagerShift
    {
        DB::beginTransaction();
        try {
            if (!$managerShift->can_reopen) {
                throw new \Exception('This shift cannot be reopened');
            }

            if (!$managerShift->shift_date->isToday()) {
                throw new \Exception('Can only reopen shift on the same day');
            }

            $managerShift->update([
                'daily_report_submitted' => false,
                'daily_report_submitted_at' => null,
                'reopened_at' => now(),
                'reopen_reason' => $reason,
                'can_reopen' => false, // Disable further reopening
            ]);

            DB::commit();
            return $managerShift->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    /**
     * Get end shift details with handover information - Section D
     */
    public function getEndShiftDetails(BranchManagerShift $managerShift): array
    {
        // Get last cashier handover
        $lastCashierHandover = $managerShift->cashierShifts()
            ->where('status', ShiftStatus::COMPLETED)
            ->whereNotNull('handed_over_at')
            ->with(['cashier', 'shift'])
            ->latest('handed_over_at')
            ->first();

        // Get all cashier handovers summary
        $cashierHandoversSummary = $this->getCashierHandoversSummary($managerShift);

        return [
            'can_end_shift' => $this->getPendingHandoffs($managerShift) === 0 && $managerShift->status === 'in_progress',
            'pending_cashier_handoffs' => $this->getPendingHandoffs($managerShift),

            // ✅ Cashier-to-Manager handover details
            'cashier_handovers' => $cashierHandoversSummary,

            // ✅ Last cashier who handed over
            'last_cashier_handover' => $lastCashierHandover ? [
                'from_cashier' => [
                    'id' => $lastCashierHandover->cashier->id,
                    'name' => $lastCashierHandover->cashier->name,
                ],
                'to_manager' => [
                    'id' => $managerShift->branchManager->id,
                    'name' => $managerShift->branchManager->name,
                ],
                'amount' => (float) $lastCashierHandover->closing_balance,
                'handed_over_at' => $lastCashierHandover->handed_over_at->format('Y-m-d H:i:s'),
            ] : null,

            'financial_summary' => [
                'opening_balance' => (float) $managerShift->opening_balance,
                'total_cash_collected' => (float) $managerShift->cash_collected,
                'expected_handover_amount' => (float) ($managerShift->cash_collected + $managerShift->opening_balance),
                'total_sales' => (float) $managerShift->total_sales,
            ],

            // Manager-to-Manager handover (if recording)
            'current_handover' => $managerShift->handed_over_at ? [
                'handover_amount' => (float) $managerShift->closing_balance,
                'status' => $managerShift->handover_status,
                'handover_from' => $managerShift->branchManager->name,
                'handover_to' => $managerShift->nextManager?->name,
                'handover_date' => $managerShift->handed_over_at->format('Y-m-d'),
                'handover_time' => $managerShift->handed_over_at->format('H:i'),
                'current_time_setting' => $managerShift->handover_timing,
                'notes' => $managerShift->handover_notes,
            ] : null,

            'available_managers' => $this->getAvailableNextManagers($managerShift),
        ];
    }

    /**
     * Get available managers for handover
     */
    private function getAvailableNextManagers(BranchManagerShift $managerShift): array
    {
        return BranchManager::where('branch_id', $managerShift->branch_id)
            ->where('id', '!=', $managerShift->branch_manager_id)
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
     * Complete end shift with handover - Section D (Updated)
     */
    public function completeEndShift(
        BranchManagerShift $managerShift,
        string $nextManagerId,
        float $handoverAmount,
        string $timing = 'today',
        ?string $notes = null
    ): BranchManagerShift {
        DB::beginTransaction();
        try {
            // Verify all cashier handoffs are approved
            $pendingHandoffs = $this->getPendingHandoffs($managerShift);
            if ($pendingHandoffs > 0) {
                throw new \Exception("Cannot end shift. {$pendingHandoffs} cashier handoffs pending approval.");
            }

            if (!$managerShift->canEndShift()) {
                throw new \Exception('Cannot end this shift. Status: ' . $managerShift->status);
            }

            // End the shift first
            $managerShift = $this->endShift($managerShift);

            // Set handover timestamp based on timing
            $handoverTime = $timing === 'yesterday'
                ? now()->subDay()->endOfDay()
                : now();

            // Update with handover details
            $managerShift->update([
                'next_manager_id' => $nextManagerId,
                'handover_from' => $managerShift->branch_manager_id,  // ✅ Current manager
                'handover_to' => $nextManagerId,                      // ✅ Next manager
                'handover_amount' => $handoverAmount,                 // ✅ Handover amount
                'closing_balance' => $handoverAmount,
                'handed_over_at' => $handoverTime,
                'handover_notes' => $notes,
                'handover_status' => 'pending',
                'handover_timing' => $timing,
                'expected_balance' => $managerShift->total_sales,
                'variance' => $managerShift->total_sales - $handoverAmount,
            ]);

            // Create opening balance for next manager's shift
            $nextShiftDate = $timing === 'yesterday'
                ? $managerShift->shift_date
                : $managerShift->shift_date->addDay();

            $nextManagerShift = BranchManagerShift::firstOrCreate([
                'branch_manager_id' => $nextManagerId,
                'shift_date' => $nextShiftDate,
            ], [
                'branch_id' => $managerShift->branch_id,
                'status' => 'not_started',
                'opening_balance' => $handoverAmount,
            ]);

            // Update handover status to completed once next manager shift is created
            $managerShift->update(['handover_status' => 'completed']);

            DB::commit();
            return $managerShift->fresh(['nextManager', 'branchManager']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    /**
     * Get all cashier handovers summary
     */
    public function getCashierHandoversSummary(BranchManagerShift $managerShift): array
    {
        $cashierHandovers = $managerShift->cashierShifts()
            ->where('status', ShiftStatus::COMPLETED)
            ->whereNotNull('handed_over_at')
            ->with(['cashier', 'shift', 'handoverStatus'])
            ->orderBy('handed_over_at', 'desc')
            ->get();

        return [
            'total_handovers' => $cashierHandovers->count(),
            'total_amount' => (float) $cashierHandovers->sum('closing_balance'),
            'handovers' => $cashierHandovers->map(function ($shift) {
                return [
                    'cashier_id' => $shift->cashier->id,
                    'cashier_name' => $shift->cashier->name,
                    'shift_name' => $shift->shift->name,
                    'handover_amount' => (float) $shift->closing_balance,
                    'handed_over_at' => $shift->handed_over_at->format('Y-m-d H:i:s'),
                    'approval_status' => $shift->handoverStatus?->manager_approval_status ?? 'pending',
                ];
            })->toArray(),
            'last_handover' => $cashierHandovers->first() ? [
                'from_cashier' => $cashierHandovers->first()->cashier->name,
                'amount' => (float) $cashierHandovers->first()->closing_balance,
                'time' => $cashierHandovers->first()->handed_over_at->format('H:i'),
            ] : null,
        ];
    }
}
