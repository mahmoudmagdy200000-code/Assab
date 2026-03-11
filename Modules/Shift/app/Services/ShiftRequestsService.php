<?php

namespace Modules\Shift\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorConcrete;
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
     * @param int|null $perPage when set, returns LengthAwarePaginator; otherwise Collection
     * @return Collection|LengthAwarePaginator
     */
    public function getHandoversForAuthUser(?string $status = null, ?int $perPage = null)
    {
        $user = auth()->user();
        if (!$user) {
            return $perPage ? new LengthAwarePaginatorConcrete([], 0, $perPage, 1, ['path' => request()->url()]) : collect();
        }

        $query = CashierShift::query()
            ->with([
                'handoverStatus.reviewedBy',
                'handover.handoverTo',
                'cashier:id,name,branch_id',
                'nextCashier:id,name,branch_id',
                'shift:id,name,start_time,end_time,branch_id',
                'shift.branch:id,name',
                'varianceDetails',
            ])
            ->whereHas('handoverStatus');

        $this->applyHandoverScopeByUser($query, $user);

        // Data isolation for Cashiers:
        // Pending handovers → only the designated recipient sees them (never the sender).
        // Non-pending (approved/rejected/rejected_final) → visible to both sender and recipient.
        if ($user instanceof Cashier) {
            $userId = $user->id;
            $query->where(function ($q) use ($userId) {
                // Pending: I must be the recipient (not the sender)
                $q->where(function ($pending) use ($userId) {
                    $pending->whereHas('handoverStatus', fn ($s) => $s->where('manager_approval_status', 'pending'))
                            ->where('cashier_id', '!=', $userId);
                })
                // Non-pending: visible to both parties
                ->orWhereHas('handoverStatus', fn ($s) => $s->where('manager_approval_status', '!=', 'pending'));
            });
        }

        if ($status !== null && $status !== '') {
            $query->whereHas('handoverStatus', fn($q) => $q->where('manager_approval_status', $status));
        }

        $query->orderByDesc('handed_over_at')->orderByDesc('shift_date');

        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    /**
     * Get all variances for the authenticated user.
     * Cashier: shifts where they are the primary cashier OR are listed as a responsible party
     *          in shift_variance_details (self_and_others / mixed).
     * Branch Manager: all shifts in their branch that have variance.
     *
     * @param string|null $status optional filter by responsibility_status (pending|approved|rejected)
     * @param int|null $perPage when set, returns LengthAwarePaginator; otherwise Collection
     * @return Collection|LengthAwarePaginator
     */
    public function getVariancesForAuthUser(?string $status = null, ?int $perPage = null)
    {
        $user = auth()->user();
        if (!$user) {
            return $perPage ? new LengthAwarePaginatorConcrete([], 0, $perPage, 1, ['path' => request()->url()]) : collect();
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

        // For cashiers: filter by responsibility_status on their specific detail record
        if ($status !== null && $status !== '' && $user instanceof Cashier) {
            $cashierId = $user->id;
            $query->whereHas('varianceDetails', fn($q) => $q
                ->where('responsible_cashier_id', $cashierId)
                ->where('responsibility_status', $status)
            );
        } elseif ($status !== null && $status !== '') {
            $query->whereHas('handoverStatus', fn($q) => $q->where('manager_approval_status', $status));
        }

        $query->orderByDesc('shift_date')->orderByDesc('handed_over_at');

        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    /**
     * Get all reassigned shifts for the authenticated cashier (shifts reassigned TO them).
     * Only for cashiers; returns empty if user is not a cashier.
     *
     * @param int|null $perPage when set, returns LengthAwarePaginator; otherwise Collection
     * @return Collection|LengthAwarePaginator
     */
    public function getReassignedShiftsForCashier(?int $perPage = null)
    {
        $user = auth()->user();
        if (!$user instanceof Cashier) {
            return $perPage ? new LengthAwarePaginatorConcrete([], 0, $perPage, 1, ['path' => request()->url()]) : collect();
        }

        $query = CashierShift::query()
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
            ->orderByDesc('reassigned_at');

        return $perPage ? $query->paginate($perPage) : $query->get();
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
            $query->whereHas('shift', fn($q) => $q->where('branch_id', $user->branch_id))
                ->whereHas('handover', fn($q) => $q->where('handover_to_type', 'branch_manager')->where('handover_to_id', $user->id));
        }
    }

    /**
     * Scope variance query by user role.
     * Cashier: own shifts OR shifts where they are assigned responsibility via variance details.
     * Branch Manager: all shifts in their branch.
     */
    private function applyVarianceScopeByUser($query, $user): void
    {
        if ($user instanceof Cashier) {
            $cashierId = $user->id;
            $query->where(function ($q) use ($cashierId) {
                // Primary cashier of the shift
                $q->where('cashier_id', $cashierId)
                  // OR assigned as responsible party in variance details (self_and_others / mixed)
                  ->orWhereHas('varianceDetails', fn($vd) => $vd->where('responsible_cashier_id', $cashierId));
            });
            return;
        }

        if ($user instanceof BranchManager && $user->branch_id) {
            $query->whereHas('shift', fn($q) => $q->where('branch_id', $user->branch_id));
        }
    }
}
