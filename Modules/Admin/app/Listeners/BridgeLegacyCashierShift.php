<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Shift;
use Modules\Admin\Services\ShiftCloseService;
use Modules\Shift\Events\ShiftEndedEvent;

/**
 * SRS §13 MOB-1.6 — the two-worlds cashier bridge. When a cashier closes their
 * shift in the mobile app (legacy `CashierShift`), surface it in the ASAB world:
 * upsert the matching `asab_shifts` row and mint the SHF- pipeline operation via
 * the same ShiftCloseService the dashboard uses, so it lands in the accountant
 * inbox with `origin='mobile'`.
 *
 * Guarded by `legacy_shift_id` so a shift already handled by the dashboard close
 * endpoint is never bridged twice.
 */
class BridgeLegacyCashierShift
{
    public function __construct(private readonly ShiftCloseService $shifts) {}

    public function handle(ShiftEndedEvent $event): void
    {
        $legacy = $event->shift;

        // Already bridged (or already closed on the dashboard) → skip.
        if (Shift::withoutGlobalScopes()->where('legacy_shift_id', $legacy->id)->exists()) {
            return;
        }

        // Resolve the ASAB employee mirroring this legacy cashier.
        $employee = Employee::where('legacy_cashier_id', $legacy->cashier_id)->first();
        if ($employee === null || $employee->branch_id === null) {
            return; // no ASAB counterpart — nothing to review on the dashboard
        }

        // The system needs an ASAB user to attribute the operation to; use a
        // reviewer of the same company (accountant/head/admin). Without one the
        // bridge can't create a pipeline op, so it no-ops.
        $actor = $this->systemActor($employee->company_id);
        if ($actor === null) {
            return;
        }

        $sales = $this->toHalalas($legacy->total_sales);
        $collected = $this->toHalalas($legacy->cash_collected);
        $float = $this->toHalalas($legacy->opening_balance);
        $card = $this->toHalalas($legacy->card_payments);

        // The mobile sheet splits non-cash sales across delivery aggregators
        // (Jahez/Keeta/…). Carry the per-aggregator lines AND their total so the
        // dashboard sheet equals the mobile one (FR-SAL-1) and — critically — the
        // server-derived expected-cash subtracts card + aggregators instead of
        // treating every riyal as cash (which invented a false shortage charged
        // to the cashier).
        $breakdown = $legacy->salesBreakdown()
            ->with('aggregator')
            ->get()
            ->map(fn ($row) => [
                'aggregator' => $row->aggregator?->name,
                'amountHalalas' => $this->toHalalas($row->amount),
            ])
            ->all();
        $aggregator = array_sum(array_column($breakdown, 'amountHalalas'));

        $shift = Shift::create([
            'company_id' => $employee->company_id,
            'branch_id' => $employee->branch_id,
            'cashier_employee_id' => $employee->id,
            'cashier_name' => $employee->name,
            'shift_type' => 'مسائي',
            'started_at' => $legacy->created_at ?? now(),
            'status' => 'active',                 // close() expects an open shift
            'orders_count' => 0,
            'sales_amount' => $sales,
            'opening_float' => $float,
            'legacy_shift_id' => $legacy->id,
        ]);

        // Route through the canonical close so the SHF operation + variance are
        // derived exactly as a dashboard close would produce them. `cashActual`
        // is the cash PHYSICALLY IN THE DRAWER — the native path aliases it from
        // `cashInDrawer` = opening float + cash taken (Accountant\ShiftController).
        // The mobile `cash_collected` excludes the float (its breakdown invariant
        // is total_sales = cash + card + aggregators, EndShiftRequest), so the
        // float must be added back; passing bare cash made expectedCash overshoot
        // by exactly the float and charged that phantom shortage to the cashier.
        $this->shifts->close($shift, [
            'cashActualHalalas' => $collected + $float,
            'cardTotalHalalas' => $card,
            'aggregatorTotalsHalalas' => $aggregator,
            'aggregatorBreakdown' => $breakdown,
        ], $actor, 'mobile');
    }

    private function systemActor(?string $companyId): ?AsabUser
    {
        if ($companyId === null) {
            return null;
        }

        return AsabUser::where('company_id', $companyId)
            ->whereHas('roleAssignments', fn ($q) => $q->whereIn('role_key', ['accountant', 'head', 'admin']))
            ->orderBy('created_at')
            ->first()
            ?? AsabUser::where('company_id', $companyId)->orderBy('created_at')->first();
    }

    private function toHalalas(mixed $sar): int
    {
        return (int) round(((float) $sar) * 100);
    }
}
