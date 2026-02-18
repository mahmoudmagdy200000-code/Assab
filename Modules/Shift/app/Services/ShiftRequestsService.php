<?php

namespace Modules\Shift\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Enums\ShiftStatus;

/**
 * ShiftRequestsService
 *
 * Provides handovers, variances, and reassigned shifts for the authenticated user.
 * Used by the Requests screen (cashier and branch manager).
 */
class ShiftRequestsService
{
    /**
     * Get all handovers for the authenticated user's shifts.
     * Cashier: handovers where they are the shift cashier or next cashier.
     * Branch Manager: handovers for shifts in their branch.
     *
     * @param string|null $status pending|approved|rejected|rejected_final
     * @return Collection
     */
    public function getHandoversForAuthUser(?string $status = null): Collection
    {
        $user = auth()->user();
        if (!$user) {
            return collect();
        }

        $query = CashierShift::query()
            ->with([
                'handoverStatus.reviewedBy',
                'handover.handoverTo',
                'cashier:id,name,branch_id',
                'nextCashier:id,name,branch_id',
                'shift:id,name,start_time,end_time,branch_id',
                'shift.branch:id,name',
            ])
            ->whereHas('handoverStatus');

        $this->applyHandoverScopeByUser($query, $user);

        if ($status !== null && $status !== '') {
            $query->whereHas('handoverStatus', fn($q) => $q->where('manager_approval_status', $status));
        }

        return $query->orderByDesc('handed_over_at')->orderByDesc('shift_date')->get();
    }

    /**
     * Get all variances for the authenticated user.
     * Cashier: shifts where they are the cashier and have variance.
     * Branch Manager: shifts in their branch that have variance.
     *
     * @param string|null $status optional filter by handover status
     * @return Collection
     */
    public function getVariancesForAuthUser(?string $status = null): Collection
    {
        $user = auth()->user();
        if (!$user) {
            return collect();
        }

        $query = CashierShift::query()
            ->with([
                'cashier:id,name,branch_id',
                'nextCashier:id,name,branch_id',
                'shift:id,name,start_time,end_time,branch_id',
                'shift.branch:id,name',
                'handoverStatus.reviewedBy',
                'handover.handoverTo',
                'varianceDetails.responsibleCashier',
            ])
            ->where(function ($q) {
                $q->whereRaw('ABS(COALESCE(variance, 0)) > 0.01')
                    ->orWhereHas('varianceDetails');
            });

        $this->applyVarianceScopeByUser($query, $user);

        if ($status !== null && $status !== '') {
            $query->whereHas('handoverStatus', fn($q) => $q->where('manager_approval_status', $status));
        }

        return $query->orderByDesc('shift_date')->orderByDesc('handed_over_at')->get();
    }

    /**
     * Get all reassigned shifts for the authenticated cashier (shifts reassigned TO them).
     * Only for cashiers; returns empty if user is not a cashier.
     *
     * @return Collection
     */
    public function getReassignedShiftsForCashier(): Collection
    {
        $user = auth()->user();
        if (!$user instanceof Cashier) {
            return collect();
        }

        return CashierShift::query()
            ->with([
                'cashier:id,name,branch_id',
                'shift:id,name,start_time,end_time,branch_id',
                'shift.branch:id,name',
                'originalCashier:id,name,branch_id',
                'reassignedBy:id,name',
                'nextCashier:id,name,branch_id',
                'handoverStatus',
                'handover',
                'varianceDetails',
            ])
            ->where('status', ShiftStatus::REASSIGNED)
            ->where('cashier_id', $user->id)
            ->orderByDesc('reassigned_at')
            ->get();
    }

    /**
     * Scope handover query by user role (cashier or branch manager).
     */
    private function applyHandoverScopeByUser($query, $user): void
    {
        if ($user instanceof Cashier) {
            $query->where(function ($q) use ($user) {
                $q->where('cashier_id', $user->id)
                    ->orWhere('next_cashier_id', $user->id);
            });
            return;
        }

        if ($user instanceof BranchManager && $user->branch_id) {
            $query->whereHas('shift', fn($q) => $q->where('branch_id', $user->branch_id));
        }
    }

    /**
     * Scope variance query by user role.
     */
    private function applyVarianceScopeByUser($query, $user): void
    {
        if ($user instanceof Cashier) {
            $query->where('cashier_id', $user->id);
            return;
        }

        if ($user instanceof BranchManager && $user->branch_id) {
            $query->whereHas('shift', fn($q) => $q->where('branch_id', $user->branch_id));
        }
    }
}
