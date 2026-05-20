<?php

namespace Modules\Shift\Transformers;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * VarianceSummaryResource
 *
 * Simplified variance item for the Requests screen (like the "Reason For Variance" card).
 * Only the fields needed for the list/summary view; full details via shift id (See Details).
 */
class VarianceSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        $handoverFrom = $this->cashier?->name ?? null;
        $handoverTo = $this->getHandOverToName();
        $cashHandoverAmount = (float) ($this->handover_amount ?? $this->closing_balance ?? 0);
        $varianceAmount = $this->getVarianceAmount();
        $varianceType = $this->getVarianceTypeLabel();
        $status = $this->getStatusLabel();
        $reason = $this->getReasonForVariance();
        $acceptanceMessage = $this->getAcceptanceMessage();

        return [
            'id' => $this->id,
            'shift_date' => $this->shift_date?->format('M j, Y'),
            'shift_date_iso' => $this->shift_date?->format('Y-m-d'),
            'status' => $status,
            'responsibility_status' => $this->getResponsibilityStatus(),
            'responsibility_reviewed_at' => $this->getResponsibilityReviewedAt(),
            'hand_over_from' => $handoverFrom,
            'hand_over_to' => $handoverTo,
            'cash_handover_amount' => $cashHandoverAmount,
            'variance_amount' => abs($varianceAmount),
            'variance_type' => $varianceType,
            'reason_for_variance' => $reason,
            'acceptance_message' => $acceptanceMessage,
        ];
    }

    private function getHandOverToName(): ?string
    {
        if ($this->relationLoaded('handover') && $this->handover?->handoverTo) {
            return $this->handover->handoverTo->name ?? null;
        }
        if ($this->relationLoaded('handover') && $this->handover) {
            $h = $this->handover;
            if ($h->handover_to_type === 'cashier') {
                return $this->nextCashier?->name ?? \Modules\Cashier\Models\Cashier::find($h->handover_to_id)?->name ?? null;
            }
            if ($h->handover_to_type === 'branch_manager') {
                return \Modules\BranchManagers\Models\BranchManager::find($h->handover_to_id)?->name ?? null;
            }
        }

        return $this->nextCashier?->name ?? null;
    }

    private function getVarianceAmount(): float
    {
        if ($this->relationLoaded('varianceDetails') && $this->varianceDetails->isNotEmpty()) {
            $first = $this->varianceDetails->first();

            return (float) ($first->variance_amount ?? $this->variance ?? 0);
        }
        if (is_array($this->variance) && isset($this->variance['total_variance_amount'])) {
            return (float) $this->variance['total_variance_amount'];
        }

        return (float) ($this->variance ?? 0);
    }

    private function getVarianceTypeLabel(): string
    {
        $amount = $this->getVarianceAmount();
        if ($amount > 0) {
            return 'Over';
        }
        if ($amount < 0) {
            return 'Short';
        }

        return 'None';
    }

    private function getStatusLabel(): string
    {
        $status = $this->handoverStatus?->manager_approval_status ?? null;
        if (! $status) {
            return 'Pending';
        }

        return match ($status) {
            'approved' => 'Approved',
            'rejected', 'rejected_final' => 'Rejected',
            default => 'Pending',
        };
    }

    /**
     * Responsibility status from varianceDetails (not handover status).
     * Values: not_submitted | pending | approved | rejected
     */
    private function getResponsibilityStatus(): string
    {
        if ($this->relationLoaded('varianceDetails') && $this->varianceDetails->isNotEmpty()) {
            return $this->varianceDetails->first()->responsibility_status ?? 'pending';
        }

        return 'not_submitted';
    }

    /**
     * When the manager reviewed (approved/rejected) the responsibility.
     */
    private function getResponsibilityReviewedAt(): ?string
    {
        if ($this->relationLoaded('varianceDetails') && $this->varianceDetails->isNotEmpty()) {
            return $this->varianceDetails->first()->reviewed_at?->format('Y-m-d H:i:s');
        }

        return null;
    }

    private function getReasonForVariance(): ?string
    {
        if ($this->relationLoaded('varianceDetails') && $this->varianceDetails->isNotEmpty()) {
            return $this->varianceDetails->first()->reason ?? null;
        }
        if (is_array($this->variance) && isset($this->variance['reason'])) {
            return $this->variance['reason'];
        }

        return null;
    }

    /**
     * "You accepted your part in the variance on: May 27th, 2025 - 10:16 AM"
     */
    private function getAcceptanceMessage(): ?string
    {
        $status = $this->handoverStatus?->manager_approval_status ?? null;
        if ($status !== 'approved') {
            return null;
        }
        $reviewedAt = $this->handoverStatus?->reviewed_at ?? $this->handover?->approved_at ?? null;
        if (! $reviewedAt) {
            return null;
        }
        $dt = $reviewedAt instanceof \Carbon\Carbon ? $reviewedAt : Carbon::parse($reviewedAt);
        $day = $dt->format('j');
        $suffix = match ((int) $day) {
            1, 21, 31 => 'st',
            2, 22 => 'nd',
            3, 23 => 'rd',
            default => 'th',
        };
        $datePart = $dt->format('F').' '.$day.$suffix.', '.$dt->format('Y');
        $timePart = $dt->format('g:i A');

        return "You accepted your part in the variance on: {$datePart} - {$timePart}";
    }
}
