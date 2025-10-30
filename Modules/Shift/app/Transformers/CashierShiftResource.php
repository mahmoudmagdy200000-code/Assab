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
                'is_active' => $this->shift?->is_active,
                'start_time' => optional($this->shift?->start_time)->format('H:i'),
                'end_time' => optional($this->shift?->end_time)->format('H:i'),
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
            'handover_status' => $this->whenLoaded('handoverStatus', function () {
                return [
                    'id' => $this->handoverStatus->id,
                    'status' => $this->handoverStatus->status ?? null,
                    'reviewed_by' => $this->handoverStatus->reviewedBy?->name ?? null,
                ];
            }),

            // Next Cashier
            'next_cashier' => $this->whenLoaded('nextCashier', function () {
                return [
                    'id' => $this->nextCashier->id,
                    'name' => $this->nextCashier->name,
                    'email' => $this->nextCashier->email ?? null,
                    'phone' => $this->nextCashier->phone ?? null,
                ];
            }),

            // Reassignment Info
            'reassigned_by' => $this->whenLoaded('reassignedBy', function () {
                return [
                    'id' => $this->reassignedBy->id,
                    'name' => $this->reassignedBy->name,
                ];
            }),
            'reassigned_at' => optional($this->reassigned_at)?->format('Y-m-d H:i'),
            'reassignment_reason' => $this->reassignment_reason,

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



            'variance_details' => $this->whenLoaded('varianceDetails', function () {
                return $this->varianceDetails->map(function ($detail) {
                    return [
                        'id' => $detail->id,
                        'responsible_cashier' => $detail->responsibleCashier?->name,
                        'amount' => $detail->amount,
                        'reason' => $detail->reason,
                    ];
                });
            }),
        ];
    }
}
