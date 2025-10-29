<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class ShiftDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'shift_date' => $this->shift_date?->format('Y-m-d'),
            'status' => [
                'value' => $this->status?->value ?? null,
                'label' => $this->status?->label() ?? 'Unknown',
                'color' => $this->status?->color() ?? '#999999',
            ],

            // Shift Progress
            'progress' => [
                'start_time' => $this->shift?->start_time,
                'end_time' => $this->shift?->end_time,
                'actual_start_time' => $this->actual_start_time?->format('Y-m-d H:i:s'),
                'actual_end_time' => $this->actual_end_time?->format('Y-m-d H:i:s'),
            ],

            // Shift Details
            'details' => [
                'assigned_to' => [
                    'id' => $this->cashier?->id,
                    'name' => $this->cashier?->name ?? 'Unknown',
                    'image' => $this->cashier?->image,
                ],

                'assigned_by' => $this->when(
                    $this->creator,
                    fn() => [
                        'id' => $this->creator->id,
                        'name' => $this->creator->name,
                    ],
                    fn() => [
                        'id' => $this->created_by ?? null,
                        'name' => 'System',
                    ]
                ),

                'branch_store' => [
                    'id' => $this->shift?->branch_id,
                    'name' => $this->shift?->branch?->name,
                ],

                'next_cashier' => $this->when($this->next_cashier_id, [
                    'id' => $this->nextCashier?->id,
                    'name' => $this->nextCashier?->name,
                    'image' => $this->nextCashier?->image,
                ]),

                'original_cashier' => $this->when($this->original_cashier_id, [
                    'id' => $this->originalCashier?->id,
                    'name' => $this->originalCashier?->name,
                ]),

                'reassigned_by' => $this->when($this->reassigned_by, [
                    'id' => $this->reassignedBy?->id,
                    'name' => $this->reassignedBy?->name,
                ]),

                'reassignment_reason' => $this->reassignment_reason,
            ],

            // Sales and Handover
            'sales' => [
                'total_sales' => (float) ($this->total_sales ?? 0),
                'net_sales' => (float) ($this->net_sales ?? 0),
                'vat_amount' => (float) ($this->vat_amount ?? 0),
                'breakdown' => [
                    'cash_collected' => (float) ($this->cash_collected ?? 0),
                    'card_payments' => (float) ($this->card_payments ?? 0),
                    'aggregators' => $this->salesBreakdown?->map(function ($breakdown) {
                        return [
                            'aggregator' => [
                                'id' => $breakdown->aggregator?->id,
                                'name' => $breakdown->aggregator?->name,
                                'logo' => $breakdown->aggregator?->logo,
                            ],
                            'amount' => (float) ($breakdown->amount ?? 0),
                            'notes' => $breakdown->notes,
                        ];
                    }) ?? [],
                ],
                'pos_receipt' => $this->pos_receipt ? asset('storage/' . $this->pos_receipt) : null,
            ],

            // Handover Details
            'handover' => $this->when($this->handoverStatus, [
                'status' => [
                    'value' => $this->handoverStatus?->status?->value ?? null,
                    'label' => $this->handoverStatus?->status?->label() ?? 'Unknown',
                ],
                'handover_amount' => (float) ($this->closing_balance ?? 0),
                'variance' => (float) ($this->variance ?? 0),
                'variance_type' => $this->variance > 0 ? 'Over' : ($this->variance < 0 ? 'Short' : 'None'),
                'handover_to' => $this->when($this->next_cashier_id, [
                    'id' => $this->nextCashier?->id,
                    'name' => $this->nextCashier?->name,
                ]),
                'handover_notes' => $this->handover_notes,
                'handed_over_at' => $this->handed_over_at?->format('Y-m-d H:i:s'),
                'reviewed_by' => $this->when($this->handoverStatus?->reviewed_by, [
                    'id' => $this->handoverStatus?->reviewedBy?->id,
                    'name' => $this->handoverStatus?->reviewedBy?->name,
                ]),
                'rejection_reason' => $this->handoverStatus?->rejection_reason,
                'rejection_files' => collect(json_decode($this->handoverStatus?->rejection_files ?? '[]'))->map(fn($file) => asset('storage/' . $file)),
                'manager_comment' => $this->handoverStatus?->manager_comment,
                'reviewed_at' => $this->handoverStatus?->reviewed_at?->format('Y-m-d H:i:s'),
            ]),

            // Variance Details
            'variance_details' => $this->when(
                $this->varianceDetails?->isNotEmpty(),
                $this->varianceDetails->map(function ($detail) {
                    return [
                        'variance_amount' => (float) ($detail->variance_amount ?? 0),
                        'variance_type' => [
                            'value' => $detail->variance_type?->value ?? null,
                            'label' => $detail->variance_type?->label() ?? 'Unknown',
                        ],
                        'responsibility_type' => [
                            'value' => $detail->responsibility_type?->value ?? null,
                            'label' => $detail->responsibility_type?->label() ?? 'Unknown',
                        ],
                        'responsible_cashier' => $detail->responsible_cashier_id ? [
                            'id' => $detail->responsibleCashier?->id,
                            'name' => $detail->responsibleCashier?->name,
                        ] : null,
                        'assigned_amount' => (float) ($detail->assigned_amount ?? 0),
                        'reason' => $detail->reason,
                        'supporting_files' => collect(json_decode($detail->supporting_files ?? '[]'))->map(fn($file) => asset('storage/' . $file)),
                    ];
                })
            ),
            'progress_data' => $this->progress_data ?? [
                'progress' => 0,
                'elapsed_minutes' => 0,
                'total_minutes' => 0,
            ],

            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
