<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

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
            // Detailed Handover Info
            'handover_details' => $this->when(
                $this->relationLoaded('handover') && $this->handover,
                function () {
                    $handover = $this->handover;
                    return [
                        'handover_from' => $this->cashier?->name ?? null,
                        'handover_from_id' => $this->cashier_id ?? null,
                        'handover_to' => $handover->handoverTo?->name ?? null,
                        'handover_to_id' => $handover->handover_to_id ?? null,
                        'handover_to_type' => $handover->handover_to_type ?? null,
                        'handover_date' => $handover->handover_date?->format('Y-m-d') ?? null,
                        'handover_time' => $handover->handover_time?->format('H:i:s') ?? null,
                        'actioned_by' => $handover->approvedBy ? [
                            'id' => $handover->approved_by_id,
                            'name' => $handover->approvedBy->name,
                            'type' => $handover->approved_by_type,
                            'actioned_at' => $handover->approved_at?->format('Y-m-d H:i:s'),
                        ] : null,
                    ];
                }
            ),

            // Next Cashier (computed from next shift when available, else stored)
            'next_cashier' => $this->formatNextCashier($this->computed_next_cashier ?? $this->nextCashier ?? null),

            // Handover To (who received the handover - cashier or branch manager)
            'handover_to' => $this->getHandoverTo(),

            // Reassignment Info (Only show if shift was reassigned)
            'reassignment' => $this->when(
                $this->status?->value === 'reassigned' || $this->original_cashier_id || $this->reassigned_by,
                function () {
                    // Load relationships if not already loaded
                    if (!$this->relationLoaded('originalCashier') && $this->original_cashier_id) {
                        $this->loadMissing('originalCashier');
                    }
                    if (!$this->relationLoaded('reassignedBy') && $this->reassigned_by) {
                        $this->loadMissing('reassignedBy');
                    }

                    return [
                        'reassigned_from' => [
                            'id' => $this->originalCashier?->id ?? $this->original_cashier_id,
                            'name' => $this->originalCashier?->name ?? null,
                        ],
                        'reassigned_to' => [
                            'id' => $this->cashier?->id ?? $this->cashier_id,
                            'name' => $this->cashier?->name ?? null,
                        ],
                        'reassigned_by' => [
                            'id' => $this->reassignedBy?->id ?? $this->reassigned_by,
                            'name' => $this->reassignedBy?->name ?? null,
                        ],
                        'reassigned_at' => $this->reassigned_at?->format('Y-m-d H:i:s'),
                        'reason' => $this->reassignment_reason,
                    ];
                }
            ),

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
                    try {
                        $varianceService = app(\Modules\Shift\Services\VarianceCalculationService::class);
                        return $varianceService->getVarianceFormatted($this->resource);
                    } catch (\Exception $e) {
                        Log::error('Error calculating variance in CashierShiftResource', [
                            'shift_id' => $this->id,
                            'error' => $e->getMessage()
                        ]);
                        return null;
                    }
                }
            ),
        ];
    }

    /**
     * @param \Modules\Cashier\Models\Cashier|null $cashier
     * @return array<string, mixed>|null
     */
    private function formatNextCashier($cashier): ?array
    {
        if (!$cashier) {
            return null;
        }

        return [
            'id' => $cashier->id,
            'name' => $cashier->name,
            'email' => $cashier->email ?? null,
            'phone' => $cashier->phone ?? null,
        ];
    }

    /**
     * Get handover_to information (who received the handover)
     * Supports both cashier and branch_manager handovers
     *
     * @return array|null
     */
    private function getHandoverTo(): ?array
    {
        // Try to get from handover relationship first (most accurate)
        if ($this->relationLoaded('handover') && $this->handover) {
            $handover = $this->handover;
            
            // Load handoverTo relationship if not loaded
            if (!$handover->relationLoaded('handoverTo')) {
                $handover->load('handoverTo');
            }
            
            $handoverTo = $handover->handoverTo;
            
            if ($handoverTo) {
                return [
                    'id' => $handover->handover_to_id,
                    'name' => $handoverTo->name ?? 'N/A',
                    'type' => $handover->handover_to_type, // 'cashier' or 'branch_manager'
                    'email' => $handoverTo->email ?? null,
                    'phone' => $handoverTo->phone ?? null,
                ];
            }
        }
        
        // Fallback: if handover relationship is not loaded, try to get from CashierShiftHandover directly
        if ($this->handed_over_at || $this->relationLoaded('handoverStatus')) {
            $handover = \Modules\Shift\Models\CashierShiftHandover::where('cashier_shift_id', $this->id)
                ->first();
            
            if ($handover) {
                // Load handoverTo based on type
                $handoverTo = null;
                if ($handover->handover_to_type === 'cashier') {
                    $handoverTo = \Modules\Cashier\Models\Cashier::find($handover->handover_to_id);
                } elseif ($handover->handover_to_type === 'branch_manager') {
                    $handoverTo = \Modules\BranchManagers\Models\BranchManager::find($handover->handover_to_id);
                }
                
                if ($handoverTo) {
                    return [
                        'id' => $handover->handover_to_id,
                        'name' => $handoverTo->name ?? 'N/A',
                        'type' => $handover->handover_to_type,
                        'email' => $handoverTo->email ?? null,
                        'phone' => $handoverTo->phone ?? null,
                    ];
                }
            }
        }
        
        // Fallback: use nextCashier (stored) or computed_next_cashier (from next shift)
        $cashier = $this->nextCashier ?? $this->computed_next_cashier ?? null;
        if ($cashier) {
            return [
                'id' => $cashier->id,
                'name' => $cashier->name,
                'type' => 'cashier',
                'email' => $cashier->email ?? null,
                'phone' => $cashier->phone ?? null,
            ];
        }

        return null;
    }
}
