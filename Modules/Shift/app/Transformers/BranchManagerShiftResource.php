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

            // Handover - FIXED
            'handover' => [
                'opening_balance' => (float) $this->opening_balance,
                'closing_balance' => (float) $this->closing_balance,
                'expected_balance' => (float) $this->expected_balance,
                'variance' => (float) $this->variance,
                'variance_type' => $this->variance > 0 ? 'Over' : ($this->variance < 0 ? 'Short' : 'None'),
                'handed_over_at' => $this->handed_over_at?->format('Y-m-d H:i:s'),
                'handover_status' => $this->handover_status ?? 'not_submitted',
                'handover_timing' => $this->handover_timing,
                'handover_amount' => (float) ($this->handover_amount ?? $this->closing_balance),

                // ✅ Fixed: Use whenLoaded to avoid errors
                'handover_from' => $this->whenLoaded('handoverFrom', function () {
                    return $this->handoverFrom ? [
                        'id' => $this->handoverFrom->id,
                        'name' => $this->handoverFrom->name,
                        'email' => $this->handoverFrom->email ?? null,
                    ] : null;
                }),

                'handover_to' => $this->whenLoaded('handoverTo', function () {
                    return $this->handoverTo ? [
                        'id' => $this->handoverTo->id,
                        'name' => $this->handoverTo->name,
                        'email' => $this->handoverTo->email ?? null,
                    ] : null;
                }),

                'next_manager' => $this->whenLoaded('nextManager', function () {
                    return $this->nextManager ? [
                        'id' => $this->nextManager->id,
                        'name' => $this->nextManager->name,
                        'email' => $this->nextManager->email ?? null,
                    ] : null;
                }),

                'handover_notes' => $this->handover_notes,
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
            'branch_manager' => $this->whenLoaded('branchManager', function () {
                return [
                    'id' => $this->branchManager->id,
                    'name' => $this->branchManager->name,
                    'email' => $this->branchManager->email ?? null,
                ];
            }),

            // Branch Info
            'branch' => $this->whenLoaded('branch', function () {
                return [
                    'id' => $this->branch->id,
                    'name' => $this->branch->name,
                ];
            }),

            // Daily Report
            'daily_report' => [
                'submitted' => $this->daily_report_submitted,
                'submitted_at' => $this->daily_report_submitted_at?->format('Y-m-d H:i:s'),
                'notes' => $this->daily_report_notes,
                'can_reopen' => $this->can_reopen,
                'reopened_at' => $this->reopened_at?->format('Y-m-d H:i:s'),
                'reopen_reason' => $this->reopen_reason,
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
        return match ($this->status) {
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
            $actions['approve_handoffs'] = true;
        }

        if ($this->status === 'completed') {
            if (!$this->handed_over_at) {
                $actions['record_handover'] = true;
            }

            if (!$this->daily_report_submitted) {
                $actions['submit_daily_report'] = true;
                $actions['view_final_daily_close'] = true;
            }

            if ($this->can_reopen && $this->daily_report_submitted && $this->shift_date->isToday()) {
                $actions['reopen_shift'] = true;
            }
        }

        return $actions;
    }
}
