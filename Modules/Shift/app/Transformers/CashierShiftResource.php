<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Modules\Shift\Enums\ShiftStatus;

class CashierShiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Status-aware: in_progress, completed, reassigned, not_started each get their required fields.
     */
    public function toArray($request)
    {
        $status = $this->status?->value ?? $this->status;

        return [
            'id' => $this->id,
            'shift_date' => $this->shift_date?->format('Y-m-d'),
            'status' => $status,

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
                    'user_type' => $this->assigned_by ? 'branch_manager' : null,
                ],
            ],

            // Financials (always include; for in_progress closing_balance may be 0)
            'opening_balance' => $this->opening_balance,
            'closing_balance' => (float) ($this->closing_balance ?? 0),
            'expected_balance' => $this->expected_balance,
            'variance' => $this->getVarianceValue(),
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
            'handover_status' => $this->getHandoverStatusArray(),
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

            // Assigned to (who the shift is assigned to)
            'assigned_to' => $this->cashier ? [
                'id' => $this->cashier->id,
                'name' => $this->cashier->name,
            ] : null,

            // Handover To (who received / will receive the handover - cashier or branch manager)
            'handover_to' => $this->getHandoverTo(),

            // handover_approved_or_rejected_by (for completed)
            'handover_approved_or_rejected_by' => $this->getHandoverApprovedOrRejectedBy(),

            // ---------- Reassignment (reassigned only) ----------
            'reassignment' => $this->when(
                $status === 'reassigned' || $this->original_cashier_id || $this->reassigned_by,
                fn() => $this->getReassignmentArray()
            ),
            'is_mid_reassign' => $this->when($status === 'reassigned', fn() => $this->isMidReassign()),
            'can_be_accepted' => $this->when($status === 'reassigned', fn() => $this->canBeAccepted()),

            // ---------- Pending (not_started): cash_from, cash_given ----------
            'cash_from' => $this->when($status === 'not_started', $this->getCashFrom()),
            'cash_given' => $this->when($status === 'not_started', (float) ($this->opening_balance ?? 0)),

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

            // Detailed variance (when loaded and has variance)
            'variance_details' => $this->when(
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
     * Variance: always a numeric float.
     * Detailed breakdown lives in variance_details only.
     */
    private function getVarianceValue(): float
    {
        return (float) ($this->variance ?? 0);
    }

    /**
     * Handover status for completed/list: id, status, reviewed_by, approved/rejected by.
     */
    private function getHandoverStatusArray(): ?array
    {
        if (!$this->relationLoaded('handoverStatus') || !$this->handoverStatus) {
            return null;
        }
        $hs = $this->handoverStatus;
        return [
            'id' => $hs->id,
            'status' => $hs->status?->value ?? $hs->manager_approval_status ?? null,
            'manager_approval_status' => $hs->manager_approval_status ?? null,
            'reviewed_by' => $hs->reviewedBy?->name ?? null,
            'reviewed_by_type' => $hs->reviewer_type ?? null,
        ];
    }

    /**
     * Who approved or rejected the handover (for completed shifts).
     */
    private function getHandoverApprovedOrRejectedBy(): ?array
    {
        if (!$this->relationLoaded('handoverStatus') || !$this->handoverStatus) {
            return null;
        }
        $hs = $this->handoverStatus;
        if (!$hs->reviewed_by_id && !$hs->reviewedBy) {
            return null;
        }
        return [
            'id' => $hs->reviewed_by_id,
            'name' => $hs->reviewedBy?->name ?? null,
            'user_type' => $hs->reviewer_type ?? null,
            'action' => $hs->manager_approval_status === 'approved' ? 'approved' : ($hs->isManagerRejected() ? 'rejected' : 'pending'),
            'reviewed_at' => $hs->reviewed_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Reassignment block: reassigned_from, reassigned_to, reassigned_by (name + user_type), cash_given, next_cashier.
     */
    private function getReassignmentArray(): array
    {
        if (!$this->relationLoaded('originalCashier') && $this->original_cashier_id) {
            $this->loadMissing('originalCashier');
        }
        if (!$this->relationLoaded('reassignedBy') && $this->reassigned_by) {
            $this->loadMissing('reassignedBy');
        }

        $cashGiven = (float) ($this->opening_balance ?? 0);
        if ($this->relationLoaded('handover') && $this->handover && $this->handover->handover_amount !== null) {
            $cashGiven = (float) $this->handover->handover_amount;
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
                'user_type' => $this->reassigned_by ? 'branch_manager' : 'cashier',
            ],
            'reassigned_at' => $this->reassigned_at?->format('Y-m-d H:i:s'),
            'reason' => $this->reassignment_reason,
            'cash_given' => $cashGiven,
            'next_cashier' => $this->formatNextCashier($this->computed_next_cashier ?? $this->nextCashier ?? null),
        ];
    }

    /**
     * True when status is reassigned and handover is pending (mid-shift reassign, not yet accepted).
     */
    private function isMidReassign(): bool
    {
        if (($this->status?->value ?? '') !== 'reassigned') {
            return false;
        }
        if (!$this->relationLoaded('handoverStatus')) {
            $this->loadMissing('handoverStatus');
        }
        return $this->handoverStatus
            && ($this->handoverStatus->manager_approval_status ?? '') === 'pending';
    }

    /**
     * True when this reassigned shift can be accepted by the assigned cashier (pending acceptance).
     */
    private function canBeAccepted(): bool
    {
        if (!$this->isMidReassign()) {
            return false;
        }
        $user = auth()->user();
        if (!$user) {
            return true; // let frontend decide by cashier_id
        }
        $cashierId = $user->getKey();
        if ($user->getMorphClass() === \Modules\Cashier\Models\Cashier::class) {
            return $this->cashier_id === $cashierId;
        }
        return false;
    }

    /**
     * For pending (not_started): who the cash is from (e.g. "Me" / branch manager / previous cashier).
     */
    private function getCashFrom(): ?array
    {
        if ($this->assignedBy) {
            return [
                'id' => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
                'user_type' => 'branch_manager',
            ];
        }
        return null;
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
