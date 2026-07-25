<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Modules\Shift\Services\ShiftService;

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
            'actual_start_time' => optional($this->actual_start_time)->format('Y-m-d H:i:s'),
            'actual_end_time' => optional($this->actual_end_time)->format('Y-m-d H:i:s'),
            'handed_over_at' => optional($this->handed_over_at)?->format('Y-m-d H:i'),

            // Handover Info
            'handover_notes' => $this->handover_notes,
            'handover_amount' => $this->when(
                $this->relationLoaded('handover') && $this->handover,
                fn () => (float) ($this->handover->handover_amount ?? 0),
                fn () => $this->handover_amount ? (float) $this->handover_amount : null
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
                            'type' => $this->normalizeActionedByType($handover->approved_by_type),
                            'actioned_at' => $handover->approved_at?->format('Y-m-d H:i:s'),
                        ] : null,
                    ];
                }
            ),

            // Next Cashier / Branch Manager (next shift cashier, or branch manager for last shift of day)
            'next_cashier' => $this->formatNextRecipient($this->getNextRecipientForDisplay()),

            // Assigned to (who the shift is assigned to)
            'assigned_to' => $this->cashier ? [
                'id' => $this->cashier->id,
                'name' => $this->cashier->name,
            ] : null,

            // Handover To (who received / will receive the handover - cashier or branch manager)
            'handover_to' => $this->getHandoverTo(),

            // handover_approved_or_rejected_by (for completed)
            'handover_approved_or_rejected_by' => $this->getHandoverApprovedOrRejectedBy(),

            // ---------- Unified for all statuses (same keys, null/empty when N/A) ----------
            'reassignment' => $this->getReassignmentOrNull(),
            'is_mid_reassign' => $this->isMidReassign(),
            'can_be_accepted' => $this->canBeAccepted(),
            'is_shift_reassigned_to_me' => $this->isShiftReassignedToMe(),
            'cash_given' => $this->getCashGivenValue(),
            'previous_cashier' => $this->getPreviousCashierName(),
            'cash_from' => $this->getCashFromUnified(),
            'reassign_reason' => $this->reassignment_reason,
            'variance_reason' => $this->getVarianceReasonValue(),

            // Always array (empty when no breakdown)
            'sales_breakdown' => $this->relationLoaded('salesBreakdown')
                ? $this->salesBreakdown->map(fn ($item) => [
                    'id' => $item->id,
                    'aggregator' => $item->aggregator?->name,
                    'amount' => (float) $item->amount,
                ])->values()->all()
                : [],

            // Always present: object when has variance details, null otherwise
            'variance_details' => $this->getVarianceDetailsOrNull(),

            // Two-worlds review decision (dashboard accountant/head → mobile).
            // Null until the sheet is reviewed on the dashboard; 'approved' shows
            // «معتمدة», 'rejected' shows «مرفوضة» + the reason. Distinct from the
            // handover approval above — this is the sales-sheet approval loop.
            'review_status' => $this->review_status,
            'reviewed_at' => optional($this->reviewed_at)->format('Y-m-d H:i:s'),
            'review_reason' => $this->review_reason,
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

    /** Reassignment block for all statuses; null when not reassigned. */
    private function getReassignmentOrNull(): ?array
    {
        $status = $this->status?->value ?? $this->status;
        if ($status !== 'reassigned' && ! $this->original_cashier_id && ! $this->reassigned_by) {
            return null;
        }

        return $this->getReassignmentArray();
    }

    /** Cash given: opening balance or handover amount; always numeric. */
    private function getCashGivenValue(): float
    {
        if ($this->relationLoaded('handover') && $this->handover && $this->handover->handover_amount !== null) {
            return (float) $this->handover->handover_amount;
        }

        return (float) ($this->opening_balance ?? 0);
    }

    /** Previous cashier name (reassigned_from or who handed over). */
    private function getPreviousCashierName(): ?string
    {
        if ($this->original_cashier_id && $this->relationLoaded('originalCashier')) {
            return $this->originalCashier?->name ?? null;
        }
        if ($this->original_cashier_id) {
            $this->loadMissing('originalCashier');

            return $this->originalCashier?->name ?? null;
        }

        return null;
    }

    /** Who the cash is from: assigned_by or reassigned_by; same shape for all statuses. */
    private function getCashFromUnified(): ?array
    {
        $status = $this->status?->value ?? $this->status;
        if ($status === 'reassigned' && $this->reassigned_by) {
            if (! $this->relationLoaded('reassignedBy')) {
                $this->loadMissing('reassignedBy');
            }

            return $this->reassignedBy ? [
                'id' => $this->reassignedBy->id,
                'name' => $this->reassignedBy->name,
                'user_type' => 'branch_manager',
            ] : null;
        }

        return $this->getCashFrom();
    }

    /** Variance reason from varianceDetails or handover; null when empty. */
    private function getVarianceReasonValue(): ?string
    {
        if ($this->relationLoaded('varianceDetails') && $this->varianceDetails->isNotEmpty()) {
            $reason = $this->varianceDetails->first()->reason ?? null;

            return $reason && trim((string) $reason) !== '' ? trim($reason) : null;
        }
        if ($this->relationLoaded('handover') && $this->handover && ! empty(trim((string) ($this->handover->variance_reason ?? '')))) {
            return trim($this->handover->variance_reason);
        }

        return null;
    }

    /** Variance details object or null; key always present in response. */
    private function getVarianceDetailsOrNull(): ?array
    {
        if (! $this->hasVariance() || ! $this->relationLoaded('varianceDetails') || $this->varianceDetails->isEmpty()) {
            return null;
        }
        try {
            return app(\Modules\Shift\Services\VarianceCalculationService::class)
                ->getVarianceFormatted($this->resource);
        } catch (\Exception $e) {
            Log::error('Error calculating variance in CashierShiftResource', [
                'shift_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Handover status for completed/list: id, status, reviewed_by, approved/rejected by.
     */
    private function getHandoverStatusArray(): ?array
    {
        if (! $this->relationLoaded('handoverStatus') || ! $this->handoverStatus) {
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
        if (! $this->relationLoaded('handoverStatus') || ! $this->handoverStatus) {
            return null;
        }
        $hs = $this->handoverStatus;
        if (! $hs->reviewed_by_id && ! $hs->reviewedBy) {
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
        if (! $this->relationLoaded('originalCashier') && $this->original_cashier_id) {
            $this->loadMissing('originalCashier');
        }
        if (! $this->relationLoaded('reassignedBy') && $this->reassigned_by) {
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
            'next_cashier' => $this->formatNextRecipient($this->getNextRecipientForDisplay()),
        ];
    }

    /**
     * True when this shift was reassigned and the current user is the one it was reassigned to.
     */
    private function isShiftReassignedToMe(): bool
    {
        $status = $this->status?->value ?? $this->status;
        if ($status !== 'reassigned') {
            return false;
        }
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return (string) $this->cashier_id === (string) $user->getKey();
    }

    /**
     * True when status is reassigned and handover is pending (mid-shift reassign, not yet accepted).
     */
    private function isMidReassign(): bool
    {
        if (($this->status?->value ?? '') !== 'reassigned') {
            return false;
        }
        if (! $this->relationLoaded('handoverStatus')) {
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
        if (! $this->isMidReassign()) {
            return false;
        }
        $user = auth()->user();
        if (! $user) {
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
     * Next recipient for display: from ShiftService (next cashier or branch manager for last shift).
     */
    private function getNextRecipientForDisplay(): \Modules\Cashier\Models\Cashier|\Modules\BranchManagers\Models\BranchManager|null
    {
        $recipient = app(ShiftService::class)->getNextRecipientForDisplay($this->resource);
        if ($recipient !== null) {
            return $recipient;
        }

        return $this->computed_next_cashier ?? $this->nextCashier ?? null;
    }

    /**
     * @param  \Modules\Cashier\Models\Cashier|\Modules\BranchManagers\Models\BranchManager|null  $recipient
     * @return array<string, mixed>|null
     */
    private function formatNextRecipient($recipient): ?array
    {
        if (! $recipient) {
            return null;
        }

        $arr = [
            'id' => $recipient->id,
            'name' => $recipient->name,
            'email' => $recipient->email ?? null,
            'phone' => $recipient->phone ?? null,
        ];
        if ($recipient instanceof \Modules\BranchManagers\Models\BranchManager) {
            $arr['type'] = 'branch_manager';
            $arr['name'] = $recipient->name.' (Branch Manager)';
        }

        return $arr;
    }

    /**
     * Get handover_to information (who received the handover)
     * Supports both cashier and branch_manager handovers
     */
    private function getHandoverTo(): ?array
    {
        // Try to get from handover relationship first (most accurate)
        if ($this->relationLoaded('handover') && $this->handover) {
            $handover = $this->handover;

            // Load handoverTo relationship if not loaded
            if (! $handover->relationLoaded('handoverTo')) {
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

        // Fallback: next recipient (next cashier or branch manager for last shift of day)
        $recipient = $this->getNextRecipientForDisplay();
        if ($recipient) {
            $type = $recipient instanceof \Modules\BranchManagers\Models\BranchManager ? 'branch_manager' : 'cashier';
            $name = $recipient->name;
            if ($recipient instanceof \Modules\BranchManagers\Models\BranchManager) {
                $name = $recipient->name.' (Branch Manager)';
            }

            return [
                'id' => $recipient->id,
                'name' => $name,
                'type' => $type,
                'email' => $recipient->email ?? null,
                'phone' => $recipient->phone ?? null,
            ];
        }

        return null;
    }

    /**
     * Normalize approved_by_type to branch_manager or cashier (API contract).
     */
    private function normalizeActionedByType(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        if (str_contains($type, 'BranchManager') || $type === 'branch_manager') {
            return 'branch_manager';
        }
        if (str_contains($type, 'Cashier') || $type === 'cashier') {
            return 'cashier';
        }

        return $type;
    }
}
