<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class BranchManagerShiftResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'shift_date' => $this->shift_date->format('Y-m-d'),
            'shift_date_formatted' => $this->shift_date->format('d M Y'),
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),

            // Timing
            'actual_start_time' => $this->actual_start_time?->format('H:i'),
            'actual_end_time' => $this->actual_end_time?->format('H:i'),
            'duration_minutes' => $this->actual_start_time && $this->actual_end_time
                ? $this->actual_start_time->diffInMinutes($this->actual_end_time)
                : null,

            // Financial Data
            'financial' => [
                'total_sales' => (float) $this->total_sales,
                'net_sales' => (float) $this->net_sales,
                'vat_amount' => (float) $this->vat_amount,
                'cash_collected' => (float) $this->cash_collected,
                'card_payments' => (float) $this->card_payments,
                'aggregator_payments' => (float) $this->aggregator_payments,
            ],

            // Handover
            'handover' => [
                'opening_balance' => (float) $this->opening_balance,
                'closing_balance' => (float) $this->closing_balance,
                'expected_balance' => (float) $this->expected_balance,
                'variance' => (float) $this->variance,
                'variance_type' => $this->variance > 0 ? 'Over' :
                                  ($this->variance < 0 ? 'Short' : 'None'),
                'handed_over_at' => $this->handed_over_at?->format('Y-m-d H:i:s'),
                'handover_notes' => $this->handover_notes,
                'next_manager' => $this->nextManager ? [
                    'id' => $this->nextManager->id,
                    'name' => $this->nextManager->name,
                ] : null,
            ],

            // Statistics
            'statistics' => [
                'total_cashier_shifts' => $this->total_cashier_shifts,
                'completed_cashier_shifts' => $this->completed_cashier_shifts,
                'pending_cashier_shifts' => $this->pending_cashier_shifts,
                'completion_rate' => $this->total_cashier_shifts > 0
                    ? round(($this->completed_cashier_shifts / $this->total_cashier_shifts) * 100, 2)
                    : 0,
            ],

            // Manager Info
            'branch_manager' => [
                'id' => $this->branchManager->id,
                'name' => $this->branchManager->name,
            ],

            // Branch Info
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ],

            // Actions
            'actions_available' => $this->getAvailableActions(),

            // Timestamps
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }

    private function getStatusLabel(): string
    {
        return match($this->status) {
            'not_started' => 'Not Started',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            default => ucfirst($this->status),
        };
    }

    private function getAvailableActions(): array
    {
        $actions = [
            'view_details' => true,
        ];

        if ($this->status === 'not_started' && $this->shift_date->isToday()) {
            $actions['start_shift'] = true;
        }

        if ($this->status === 'in_progress') {
            $actions['end_shift'] = true;
            $actions['view_cashier_shifts'] = true;
        }

        if ($this->status === 'completed' && !$this->handed_over_at) {
            $actions['record_handover'] = true;
        }

        return $actions;
    }
}
