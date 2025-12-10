<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashierShiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'shift_date' => $this->shift_date?->format('Y-m-d'),
            'status' => $this->status?->value ?? $this->status,

            // Cashier Info
            'cashier' => [
                'id' => $this->cashier?->id,
                'name' => $this->cashier?->name,
            ],

            // Shift Info
            'shift' => [
                'id' => $this->shift?->id,
                'name' => $this->shift?->name,
                'branch_id' => $this->shift?->branch_id,
                'branch_name' => $this->shift?->branch?->name,
                'is_active' => $this->shift?->is_active ?? true,
                'start_time' => optional($this->shift?->start_time)->format('H:i'),
                'end_time' => optional($this->shift?->end_time)->format('H:i'),
                'assigned_by' => [
                    'id' => $this->assignedBy?->id,
                    'name' => $this->assignedBy?->name,
                ],
            ],

            // Financials
            'opening_balance' => $this->opening_balance,
            'closing_balance' => $this->closing_balance,
            'expected_balance' => $this->expected_balance,
            'variance' => $this->variance,
            'total_sales' => $this->total_sales,
            'net_sales' => $this->net_sales,
            'vat_amount' => $this->vat_amount,
            'cash_collected' => $this->cash_collected,
            'card_payments' => $this->card_payments,
            'pos_receipt' => $this->pos_receipt,

            // Timing Info
            'actual_start_time' => optional($this->actual_start_time)->format('H:i'),
            'actual_end_time' => optional($this->actual_end_time)->format('H:i'),
            'handed_over_at' => optional($this->handed_over_at)?->format('Y-m-d H:i'),

            // Handover Info
            'handover_notes' => $this->handover_notes,
            'handover_amount' => $this->when(
                $this->relationLoaded('handover') && $this->handover,
                fn() => (float) ($this->handover->handover_amount ?? 0),
                fn() => $this->handover_amount ? (float) $this->handover_amount : null
            ),
            'handover_status' => $this->when(
                $this->relationLoaded('handoverStatus') && $this->handoverStatus,
                function () {
                    return [
                        'id' => $this->handoverStatus->id,
                        'status' => $this->handoverStatus->status ?? null,
                        'reviewed_by' => $this->handoverStatus->reviewedBy?->name ?? null,
                    ];
                }
            ),

            // Next Cashier
            'next_cashier' => $this->whenLoaded('nextCashier', function () {
                return $this->nextCashier ? [
                    'id' => $this->nextCashier->id,
                    'name' => $this->nextCashier->name,
                    'email' => $this->nextCashier->email ?? null,
                    'phone' => $this->nextCashier->phone ?? null,
                ] : null;
            }),

            // Reassignment Info (Only show if shift was reassigned)
            'reassignment' => $this->when($this->status?->value === 'reassigned', function () {
                return [
                    'reassigned_from' => [
                        'id' => $this->originalCashier?->id,
                        'name' => $this->originalCashier?->name,
                    ],
                    'reassigned_to' => [
                        'id' => $this->cashier?->id,
                        'name' => $this->cashier?->name,
                    ],
                    'reassigned_by' => [
                        'id' => $this->reassignedBy?->id,
                        'name' => $this->reassignedBy?->name,
                    ],
                    'reassigned_at' => $this->reassigned_at?->format('Y-m-d H:i'),
                    'reason' => $this->reassignment_reason,
                ];
            }),

            // Relations
            'sales_breakdown' => $this->whenLoaded('salesBreakdown', function () {
                return $this->salesBreakdown->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'aggregator' => $item->aggregator?->name,
                        'amount' => $item->amount,
                    ];
                });
            }),

            'variance' => $this->when(
                $this->hasVariance() && $this->relationLoaded('varianceDetails'),
                function () {
                    $varianceService = app(\Modules\Shift\Services\VarianceCalculationService::class);
                    return $varianceService->getVarianceFormatted($this->resource);
                }
            ),
        ];
    }
}
