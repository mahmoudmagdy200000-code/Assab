<?php

namespace Modules\Shift\Transformers;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;

/**
 * HandoverSummaryResource
 *
 * Simplified handover item for the Requests screen (Opening Balance card).
 * Cash Given, Variance, Cash From, acceptance message, and shift id for "Go To Shift Details".
 */
class HandoverSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        $cashGiven = (float) ($this->handover_amount ?? $this->closing_balance ?? 0);
        $varianceAmount = (float) ($this->variance ?? 0);
        $varianceDisplay = $this->getVarianceDisplay($varianceAmount);
        $cashFrom = $this->cashier?->name ?? null;
        $status = $this->getStatusLabel();
        $acceptanceMessage = $this->getAcceptanceMessage();

        $authUser = auth()->user();
        $handover = $this->handover;
        $handoverToType = $handover?->handover_to_type ?? 'cashier';
        $recipientId = $handover?->handover_to_id ?? ($handoverToType === 'cashier' ? $this->next_cashier_id : null);

        $isIncoming = false;
        $isOutgoing = false;

        if ($authUser instanceof BranchManager) {
            $isIncoming = $handoverToType === 'branch_manager'
                && $recipientId !== null
                && (string) $recipientId === (string) $authUser->id
                && (string) $this->shift?->branch_id === (string) $authUser->branch_id;
        } elseif ($authUser instanceof Cashier) {
            $isIncoming = $handoverToType === 'cashier'
                && $recipientId !== null
                && (string) $recipientId === (string) $authUser->id
                && (string) $this->cashier_id !== (string) $authUser->id;
            $isOutgoing = (string) $this->cashier_id === (string) $authUser->id
                && (string) $recipientId !== (string) $authUser->id;
        }

        $managerApprovalStatus = $this->handoverStatus?->manager_approval_status ?? 'pending';
        $handoverStatus = $handover?->status ?? 'pending';
        $canBeAccepted = $isIncoming
            && $managerApprovalStatus === 'pending'
            && $handoverStatus === 'pending';

        return [
            'id' => $this->id,
            'handover_id' => $handover?->id,
            'shift_date' => $this->shift_date?->format('M j, Y'),
            'shift_date_iso' => $this->shift_date?->format('Y-m-d'),
            'status' => $status,
            'cash_given' => $cashGiven,
            'variance' => $varianceDisplay,
            'variance_amount' => $varianceAmount,
            'cash_from' => $cashFrom,
            'handover_to_id' => $recipientId,
            'handover_to_name' => $handover?->handoverTo?->name ?? $this->nextCashier?->name,
            'handover_to_type' => $handoverToType,
            'receipt_id' => $handover?->receipt?->id,
            'acceptance_message' => $acceptanceMessage,
            'responsibility_status' => $this->getResponsibilityStatus(),
            'responsibility_reviewed_at' => $this->getResponsibilityReviewedAt(),

            // Role flags — the app uses these to show/hide Accept & Reject buttons
            'is_incoming' => $isIncoming,    // true = current user is the designated receiver
            'is_outgoing' => $isOutgoing,    // true = current user sent this handover
            'can_be_accepted' => $canBeAccepted, // true = incoming AND still pending → show Accept/Reject
        ];
    }

    /**
     * Variance display: "None" when zero, otherwise the numeric value for display.
     */
    private function getVarianceDisplay(float $amount): string
    {
        if (abs($amount) < 0.01) {
            return 'None';
        }

        return (string) round($amount, 2);
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
     * Responsibility status from varianceDetails.
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
     * Timestamp of when the manager reviewed the responsibility (approved or rejected).
     */
    private function getResponsibilityReviewedAt(): ?string
    {
        if ($this->relationLoaded('varianceDetails') && $this->varianceDetails->isNotEmpty()) {
            return $this->varianceDetails->first()->reviewed_at?->format('Y-m-d H:i:s');
        }

        return null;
    }

    /**
     * "You accepted this handover on: May 27th, 2025 - 10:16 AM"
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
        $dt = $reviewedAt instanceof Carbon ? $reviewedAt : Carbon::parse($reviewedAt);
        $day = (int) $dt->format('j');
        $suffix = match ($day) {
            1, 21, 31 => 'st',
            2, 22 => 'nd',
            3, 23 => 'rd',
            default => 'th',
        };
        $datePart = $dt->format('F').' '.$day.$suffix.', '.$dt->format('Y');
        $timePart = $dt->format('g:i A');

        return "You accepted this handover on: {$datePart} - {$timePart}";
    }
}
