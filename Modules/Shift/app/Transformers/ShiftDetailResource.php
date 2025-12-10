<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

/**
 * ShiftDetailResource
 *
 * Comprehensive resource for shift details matching all UI requirements
 * Used for both Branch Manager and Cashier views
 */
class ShiftDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'shift_date' => $this->shift_date?->format('Y-m-d'),
            'status' => $this->status?->value ?? $this->status,
            'status_label' => $this->getStatusLabel(),

            // Section A: Shift Progress
            'shift_progress' => $this->getShiftProgress(),

            // Section B: Shift Details
            'shift_details' => $this->getShiftDetails(),

            // Sales Information
            'sales_info' => $this->getSalesInfo(),

            // Section C: Handover Information
            'handover_info' => $this->getHandoverInfo(),

            // Variance Information
            'variance_info' => $this->getVarianceInfo(),

            // Reassignment Information (only for reassigned shifts)
            'reassignment_info' => $this->when(
                $this->status?->value === 'reassigned' || $this->original_cashier_id,
                fn() => $this->getReassignmentInfo()
            ),

            // Available Actions
            'available_actions' => $this->getAvailableActions(),

            // Timestamps
            'timestamps' => [
                'actual_start_time' => $this->actual_start_time?->format('Y-m-d H:i:s'),
                'actual_end_time' => $this->actual_end_time?->format('Y-m-d H:i:s'),
                'handed_over_at' => $this->handed_over_at?->format('Y-m-d H:i:s'),
                'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * Get shift progress information
     * Required for: Progress bar, status display, time tracking
     */
    private function getShiftProgress(): array
    {
        $scheduledStart = $this->shift?->start_time;
        $scheduledEnd = $this->shift?->end_time;
        $actualStart = $this->actual_start_time;
        $actualEnd = $this->actual_end_time;

        // Calculate progress percentage
        $progressPercentage = 0;
        $elapsedHours = 0;
        $remainingHours = 0;
        $totalHours = 0;

        if ($scheduledStart && $scheduledEnd) {
            $start = Carbon::parse($scheduledStart);
            $end = Carbon::parse($scheduledEnd);
            $totalHours = $start->diffInMinutes($end) / 60;

            if ($this->status?->value === 'in_progress' && $actualStart) {
                $elapsedMinutes = now()->diffInMinutes($actualStart);
                $totalMinutes = $start->diffInMinutes($end);
                $progressPercentage = min(($elapsedMinutes / max($totalMinutes, 1)) * 100, 100);
                $elapsedHours = round($elapsedMinutes / 60, 2);
                $remainingHours = max(0, round(($totalMinutes - $elapsedMinutes) / 60, 2));
            } elseif ($this->status?->value === 'completed') {
                $progressPercentage = 100;
                $elapsedHours = $totalHours;
                $remainingHours = 0;
            }
        }

        return [
            'title' => $this->getProgressTitle(),
            'description' => $this->getProgressDescription(),
            'status' => $this->getStatusLabel(),
            'start_time' => $scheduledStart?->format('H:i') ?? 'N/A',
            'end_time' => $scheduledEnd?->format('H:i') ?? 'N/A',
            'total_hours' => round($totalHours, 2),
            'elapsed_hours' => $elapsedHours,
            'remaining_hours' => $remainingHours,
            'progress_percentage' => round($progressPercentage, 1),
            'can_end_now' => $this->canEndNow(),
        ];
    }

    /**
     * Get shift details
     * Required for: Shift info display
     */
    private function getShiftDetails(): array
    {
        return [
            'assigned_to' => $this->cashier?->name ?? 'N/A',
            'assigned_to_id' => $this->cashier_id,
            'assigned_by' => $this->assignedBy?->name ?? 'Branch Manager',
            'assigned_by_id' => $this->assigned_by,
            'branch_store' => $this->shift?->branch?->name ?? 'N/A',
            'branch_id' => $this->shift?->branch_id,
            'shift_name' => $this->shift?->name ?? 'N/A',
            'shift_id' => $this->shift_id,
            'start_time' => $this->shift?->start_time?->format('H:i') ?? 'N/A',
            'end_time' => $this->shift?->end_time?->format('H:i') ?? 'N/A',
            'next_cashier' => $this->nextCashier?->name ?? 'Not assigned',
            'next_cashier_id' => $this->next_cashier_id,
        ];
    }

    /**
     * Get sales information
     * Required for: Sales breakdown display
     */
    private function getSalesInfo(): array
    {
        $aggregatorsTotal = $this->whenLoaded('salesBreakdown', function () {
            return $this->salesBreakdown->sum('amount');
        }, 0);

        return [
            'total_sales' => (float) ($this->total_sales ?? 0),
            'net_sales' => (float) ($this->net_sales ?? 0),
            'vat_amount' => (float) ($this->vat_amount ?? 0),
            'sales_breakdown' => [
                'cash_collected' => (float) ($this->cash_collected ?? 0),
                'card_payments' => (float) ($this->card_payments ?? 0),
                'delivery_apps' => (float) $aggregatorsTotal,
            ],
            'aggregators' => $this->whenLoaded('salesBreakdown', function () {
                return $this->salesBreakdown->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'aggregator_id' => $item->aggregator_id,
                        'aggregator_name' => $item->aggregator?->name ?? 'Unknown',
                        'amount' => (float) $item->amount,
                        'notes' => $item->notes,
                    ];
                });
            }),
            'pos_receipt' => $this->pos_receipt ? asset('storage/' . $this->pos_receipt) : null,
            'opening_balance' => (float) ($this->opening_balance ?? 0),
            'closing_balance' => (float) ($this->closing_balance ?? 0),
        ];
    }

    /**
     * Get handover information
     * Required for: Handover status, approval workflow
     */
    private function getHandoverInfo(): ?array
    {
        // Check if handover exists
        if (!$this->handed_over_at && !$this->handoverStatus) {
            return [
                'status' => 'not_submitted',
                'status_label' => 'Not Submitted',
                'given_cash' => 'Not Recorded Yet',
            ];
        }

        $handoverStatus = $this->handoverStatus;

        // Get handover_to from handover (CashierShiftHandover) if available, otherwise from nextCashier
        $handoverToId = null;
        $handoverToName = 'N/A';
        $handover = $this->relationLoaded('handover') ? $this->handover : null;
        
        if ($handover && $handover->handover_to_id) {
            $handoverToId = $handover->handover_to_id;
            if ($handover->handover_to_type === 'cashier') {
                $handoverToName = $this->nextCashier?->name ?? 'N/A';
            } elseif ($handover->handover_to_type === 'branch_manager') {
                $manager = \Modules\BranchManagers\Models\BranchManager::find($handover->handover_to_id);
                $handoverToName = $manager?->name ?? 'N/A';
            }
        } elseif ($this->nextCashier) {
            $handoverToId = $this->nextCashier->id;
            $handoverToName = $this->nextCashier->name;
        }

        return [
            'handover_amount' => (float) ($handover?->handover_amount ?? $this->handover_amount ?? $this->closing_balance ?? 0),
            'status' => $handoverStatus?->manager_approval_status ?? 'pending',
            'status_label' => $handoverStatus?->status_label ?? 'Pending',
            'handover_from' => $this->cashier?->name ?? 'N/A',
            'handover_to' => [
                'id' => $handoverToId,
                'name' => $handoverToName,
            ],
            'handover_date' => $this->handed_over_at?->format('Y-m-d'),
            'handover_time' => $this->handed_over_at?->format('H:i:s'),
            'handover_notes' => $this->handover_notes,

            // Approval details
            'approval_details' => $handoverStatus ? [
                'reviewed_by' => $handoverStatus->reviewedBy?->name ?? null,
                'reviewed_by_type' => $handoverStatus->reviewer_type,
                'reviewed_at' => $handoverStatus->reviewed_at?->format('Y-m-d H:i:s'),
                'manager_comment' => $handoverStatus->manager_comment,
            ] : null,

            // Rejection details (for rejected handovers)
            'rejection_details' => $handoverStatus && $handoverStatus->isManagerRejected() ? [
                'rejection_reason' => $handoverStatus->rejection_reason,
                'rejection_count' => $handoverStatus->rejection_count,
                'is_final_rejection' => $handoverStatus->isPermanentlyRejected(),
                'can_edit' => $handoverStatus->canCashierEdit(),
                'first_rejected_at' => $handoverStatus->first_rejected_at?->format('Y-m-d H:i:s'),
                'second_rejected_at' => $handoverStatus->second_rejected_at?->format('Y-m-d H:i:s'),
                'rejection_files' => $handoverStatus->rejection_file_urls,
            ] : null,

            // Edit status (if edited after rejection)
            'was_edited_after_rejection' => $handoverStatus?->was_edited_after_rejection ?? false,
            'edited_at' => $handoverStatus?->edited_at?->format('Y-m-d H:i:s'),

            // Actions
            'can_approve' => $handoverStatus?->canBeApproved() ?? false,
            'can_reject' => $handoverStatus?->canBeRejected() ?? false,
        ];
    }

    /**
     * Get variance information
     * Required for: Variance display with expandable details
     */
    private function getVarianceInfo(): array
    {
        $variance = (float) ($this->variance ?? 0);
        $hasVariance = abs($variance) > 0.01;

        $result = [
            'has_variance' => $hasVariance,
            'variance_amount' => $variance,
            'variance_type' => $variance > 0 ? 'Over' : ($variance < 0 ? 'Short' : 'None'),
            'variance_type_label' => $variance > 0 ? 'Over (زيادة)' : ($variance < 0 ? 'Short (نقص)' : 'No Variance'),
        ];

        // Add formatted variance if exists
        if ($hasVariance && $this->relationLoaded('varianceDetails') && $this->varianceDetails->isNotEmpty()) {
            $varianceService = app(\Modules\Shift\Services\VarianceCalculationService::class);
            $result['variance'] = $varianceService->getVarianceFormatted($this->resource);
        }

        return $result;
    }

    /**
     * Get reassignment information
     * Required for: Reassigned shifts display
     */
    private function getReassignmentInfo(): array
    {
        return [
            'date' => $this->shift_date?->format('Y-m-d'),
            'status' => 'Reassignment',
            'reassigned_from' => $this->originalCashier?->name ?? 'N/A',
            'reassigned_from_id' => $this->original_cashier_id,
            'reassigned_to' => $this->cashier?->name ?? 'N/A',
            'reassigned_to_id' => $this->cashier_id,
            'reassigned_by' => $this->reassignedBy?->name ?? 'N/A',
            'reassigned_by_id' => $this->reassigned_by,
            'reassigned_at' => $this->reassigned_at?->format('Y-m-d H:i:s'),
            'reassignment_reason' => $this->reassignment_reason,
        ];
    }

    /**
     * Get available actions based on current status
     */
    private function getAvailableActions(): array
    {
        $status = $this->status?->value ?? 'not_started';

        return [
            'can_start' => $status === 'not_started' && $this->shift_date?->isToday(),
            'can_end' => $status === 'in_progress',
            'can_handover' => $status === 'in_progress' || ($status === 'completed' && !$this->handoverStatus?->isManagerApproved()),
            'can_reassign' => in_array($status, ['not_started', 'reassigned']),
            'can_view_details' => true,
            'can_approve_handover' => $this->handoverStatus?->canBeApproved() ?? false,
            'can_reject_handover' => $this->handoverStatus?->canBeRejected() ?? false,
            'can_edit_handover' => $this->handoverStatus?->canCashierEdit() ?? false,
        ];
    }

    /**
     * Get progress title
     */
    private function getProgressTitle(): string
    {
        $shiftName = $this->shift?->name ?? 'Shift';
        $date = $this->shift_date?->format('d M Y') ?? '';
        return "{$shiftName} - {$date}";
    }

    /**
     * Get progress description
     */
    private function getProgressDescription(): string
    {
        $branchName = $this->shift?->branch?->name ?? 'Store';
        
        $cashierName = $this->cashier?->name ?? 'Cashier';
        return "Working shift at {$branchName} assigned to {$cashierName}";
    }

    /**
     * Get human-readable status label
     */
    private function getStatusLabel(): string
    {
        return match ($this->status?->value ?? 'not_started') {
            'not_started' => 'Not Started',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'reassigned' => 'Reassigned',
            'canceled' => 'Canceled',
            default => 'Unknown',
        };
    }

    /**
     * Check if shift can be ended now
     * Business Rule: Cannot end until End Time is reached
     */
    private function canEndNow(): bool
    {
        if ($this->status?->value !== 'in_progress') {
            return false;
        }

        $endTime = $this->shift?->end_time;
        if (!$endTime) {
            return true;
        }

        // Allow ending if current time is >= scheduled end time
        $scheduledEnd = Carbon::parse($endTime)->setDate(
            now()->year,
            now()->month,
            now()->day
        );

        return now()->greaterThanOrEqualTo($scheduledEnd);
    }
}
