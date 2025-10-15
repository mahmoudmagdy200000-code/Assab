<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'shift_date' => $this->shift_date->format('Y-m-d'),
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],

            // Shift Progress
            'progress' => [
                'start_time' => $this->shift->start_time,
                'end_time' => $this->shift->end_time,
                'actual_start_time' => $this->actual_start_time?->format('Y-m-d H:i:s'),
                'actual_end_time' => $this->actual_end_time?->format('Y-m-d H:i:s'),
            ],

            // Shift Details
            'details' => [
                'assigned_to' => [
                    'id' => $this->cashier->id,
                    'name' => $this->cashier->name,
                    'image' => $this->cashier->image,
                ],
                'assigned_by' => $this->when($this->shift->created_by, [
                    'id' => $this->shift->created_by,
                    'name' => $this->shift->creator->name ?? 'System',
                ]),
                'branch_store' => [
                    'id' => $this->shift->branch_id,
                    'name' => $this->shift->branch->name,
                ],
                'next_cashier' => $this->when($this->next_cashier_id, [
                    'id' => $this->nextCashier->id,
                    'name' => $this->nextCashier->name,
                    'image' => $this->nextCashier->image,
                ]),
                'original_cashier' => $this->when($this->original_cashier_id, [
                    'id' => $this->originalCashier->id,
                    'name' => $this->originalCashier->name,
                ]),
                'reassigned_by' => $this->when($this->reassigned_by, [
                    'id' => $this->reassignedBy->id,
                    'name' => $this->reassignedBy->name,
                ]),
                'reassignment_reason' => $this->reassignment_reason,
            ],

            // Sales and Handover
            'sales' => [
                'total_sales' => (float) $this->total_sales,
                'net_sales' => (float) $this->net_sales,
                'vat_amount' => (float) $this->vat_amount,
                'breakdown' => [
                    'cash_collected' => (float) $this->cash_collected,
                    'card_payments' => (float) $this->card_payments,
                    'aggregators' => $this->salesBreakdown->map(function ($breakdown) {
                        return [
                            'aggregator' => [
                                'id' => $breakdown->aggregator->id,
                                'name' => $breakdown->aggregator->name,
                                'logo' => $breakdown->aggregator->logo,
                            ],
                            'amount' => (float) $breakdown->amount,
                            'notes' => $breakdown->notes,
                        ];
                    }),
                ],
                'pos_receipt' => $this->pos_receipt ? asset('storage/' . $this->pos_receipt) : null,
            ],

            // Handover Details
            'handover' => $this->when($this->handoverStatus, [
                'status' => [
                    'value' => $this->handoverStatus?->status?->value,
                    'label' => $this->handoverStatus?->status?->label(),
                ],
                'handover_amount' => (float) $this->closing_balance,
                'variance' => (float) $this->variance,
                'variance_type' => $this->variance > 0 ? 'Over' : ($this->variance < 0 ? 'Short' : 'None'),
                'handover_to' => $this->when($this->next_cashier_id, [
                    'id' => $this->nextCashier->id,
                    'name' => $this->nextCashier->name,
                ]),
                'handover_notes' => $this->handover_notes,
                'handed_over_at' => $this->handed_over_at?->format('Y-m-d H:i:s'),
                'reviewed_by' => $this->when($this->handoverStatus?->reviewed_by, [
                    'id' => $this->handoverStatus->reviewedBy->id,
                    'name' => $this->handoverStatus->reviewedBy->name,
                ]),
                'rejection_reason' => $this->handoverStatus?->rejection_reason,
                'rejection_files' => $this->handoverStatus?->rejection_files
                    ? collect(json_decode($this->handoverStatus->rejection_files))->map(function ($file) {
                        return asset('storage/' . $file);
                    })
                    : [],
                'manager_comment' => $this->handoverStatus?->manager_comment,
                'reviewed_at' => $this->handoverStatus?->reviewed_at?->format('Y-m-d H:i:s'),
            ]),

            // Variance Details
            'variance_details' => $this->when(
                $this->varianceDetails->isNotEmpty(),
                $this->varianceDetails->map(function ($detail) {
                    return [
                        'variance_amount' => (float) $detail->variance_amount,
                        'variance_type' => [
                            'value' => $detail->variance_type->value,
                            'label' => $detail->variance_type->label(),
                        ],
                        'responsibility_type' => [
                            'value' => $detail->responsibility_type->value,
                            'label' => $detail->responsibility_type->label(),
                        ],
                        'responsible_cashier' => $detail->responsible_cashier_id ? [
                            'id' => $detail->responsibleCashier->id,
                            'name' => $detail->responsibleCashier->name,
                        ] : null,
                        'assigned_amount' => (float) $detail->assigned_amount,
                        'reason' => $detail->reason,
                        'supporting_files' => $detail->supporting_files
                            ? collect(json_decode($detail->supporting_files))->map(function ($file) {
                                return asset('storage/' . $file);
                            })
                            : [],
                    ];
                })
            ),

            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
