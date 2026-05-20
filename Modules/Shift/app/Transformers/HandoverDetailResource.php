<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * HandoverDetailResource
 *
 * Comprehensive resource for handover details
 * Includes expandable variance information as per requirements
 */
class HandoverDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $cashierShift = $this->cashierShift;
        $shift = $cashierShift?->shift;

        return [
            'handover_id' => $this->id,
            'cashier_shift_id' => $this->cashier_shift_id,

            // Cashier Information
            'cashier' => [
                'id' => $cashierShift?->cashier_id,
                'name' => $cashierShift?->cashier?->name ?? 'N/A',
                'image' => $cashierShift?->cashier?->image
                    ? asset('storage/'.$cashierShift->cashier->image)
                    : null,
            ],

            // Shift Information
            'shift' => [
                'id' => $shift?->id,
                'name' => $shift?->name ?? 'N/A',
                'start_time' => $shift?->start_time?->format('H:i'),
                'end_time' => $shift?->end_time?->format('H:i'),
                'branch_name' => $shift?->branch?->name ?? 'N/A',
                'branch_id' => $shift?->branch_id,
            ],

            // Handover Details
            'handover_amount' => (float) $this->handover_amount,
            'total_sales' => (float) ($cashierShift?->total_sales ?? 0),

            // Status
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),

            // Variance Information (expandable)
            'variance' => $this->getVarianceDetails($cashierShift),

            // Rejection Details (if rejected)
            'rejection_details' => $this->when(
                in_array($this->status, ['rejected', 'rejected_final']),
                fn () => $this->getRejectionDetails()
            ),

            // Approval Details (if approved)
            'approval_details' => $this->when(
                $this->status === 'approved',
                fn () => $this->getApprovalDetails()
            ),

            // Timestamps
            'handover_date' => $this->handover_date?->format('Y-m-d'),
            'handover_time' => $this->handover_time?->format('H:i:s'),
            'handed_over_at' => $this->handed_over_at?->format('Y-m-d H:i:s'),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),

            // Handover To
            'handover_to' => [
                'type' => $this->handover_to_type,
                'id' => $this->handover_to_id,
                'name' => $this->getHandoverToName(),
            ],

            // Notes
            'handover_notes' => $this->handover_notes,

            // Actions
            'can_approve' => $this->canApprove(),
            'can_reject' => $this->canReject(),
        ];
    }

    /**
     * Get variance details (expandable)
     */
    private function getVarianceDetails($cashierShift): array
    {
        $variance = (float) $this->variance_amount;
        $hasVariance = abs($variance) > 0.01;

        $details = [
            'total_sales' => (float) ($cashierShift?->total_sales ?? 0),
            'handover_amount' => (float) $this->handover_amount,
            'variance_amount' => $variance,
            'variance_type' => $variance > 0 ? 'Over' : ($variance < 0 ? 'Short' : 'None'),
            'has_variance' => $hasVariance,
        ];

        // Add detailed variance breakdown if exists
        if ($hasVariance) {
            $details['reason_for_variance'] = $this->variance_reason;
            $details['attached_files'] = $this->variance_files
                ? array_map(fn ($f) => asset('storage/'.$f), $this->variance_files)
                : [];

            // Add cashier details with variance reason
            $details['cashier_details'] = [
                'id' => $cashierShift?->cashier_id,
                'name' => $cashierShift?->cashier?->name ?? 'N/A',
                'variance_reason' => $this->variance_reason,
            ];

            // Add other cashiers if variance involves multiple people
            if ($cashierShift && $cashierShift->relationLoaded('varianceDetails')) {
                $details['other_cashiers'] = $cashierShift->varianceDetails->map(function ($detail) {
                    return [
                        'cashier_id' => $detail->responsible_cashier_id,
                        'cashier_name' => $detail->responsibleCashier?->name ?? 'External Factors',
                        'amount' => (float) $detail->assigned_amount,
                        'reason' => $detail->reason,
                        'responsibility_type' => $detail->responsibility_type?->value ?? $detail->responsibility_type,
                    ];
                })->toArray();
            }
        }

        return $details;
    }

    /**
     * Get rejection details
     */
    private function getRejectionDetails(): array
    {
        return [
            'rejection_reason' => $this->rejection_reason,
            'rejection_count' => $this->rejection_count,
            'is_final_rejection' => $this->status === 'rejected_final',
            'can_edit' => $this->status === 'rejected' && $this->rejection_count < 2,
            'first_rejected_at' => $this->first_rejected_at?->format('Y-m-d H:i:s'),
            'second_rejected_at' => $this->second_rejected_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get approval details
     */
    private function getApprovalDetails(): array
    {
        return [
            'approved_by' => $this->getApprovedByName(),
            'approved_by_type' => $this->approved_by_type,
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get handover recipient name
     */
    private function getHandoverToName(): string
    {
        if ($this->handover_to_type === 'branch_manager') {
            $manager = \Modules\BranchManagers\Models\BranchManager::find($this->handover_to_id);

            return $manager?->name ?? 'Branch Manager';
        }

        $cashier = \Modules\Cashier\Models\Cashier::find($this->handover_to_id);

        return $cashier?->name ?? 'Next Cashier';
    }

    /**
     * Get approved by name
     */
    private function getApprovedByName(): ?string
    {
        if (! $this->approved_by_id) {
            return null;
        }

        if ($this->approved_by_type === 'branch_manager') {
            $manager = \Modules\BranchManagers\Models\BranchManager::find($this->approved_by_id);

            return $manager?->name;
        }

        $cashier = \Modules\Cashier\Models\Cashier::find($this->approved_by_id);

        return $cashier?->name;
    }

    /**
     * Get status label
     */
    private function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected (Awaiting Edit)',
            'rejected_final' => 'Permanently Rejected',
            default => 'Unknown',
        };
    }

    /**
     * Get status color for UI
     */
    private function getStatusColor(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'approved' => 'green',
            'rejected' => 'orange',
            'rejected_final' => 'red',
            default => 'gray',
        };
    }
}
