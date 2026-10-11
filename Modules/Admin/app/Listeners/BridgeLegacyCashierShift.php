<?php

namespace Modules\Admin\Listeners;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Shift;
use Modules\Admin\Services\BranchHierarchyLinker;
use Modules\Admin\Services\LegacyShiftMirror;
use Modules\Admin\Services\ShiftCloseService;
use Modules\Branch\Models\Branch;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Services\ShiftCashCountService;

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
    public function __construct(
        private readonly ShiftCloseService $shifts,
        private readonly BranchHierarchyLinker $branches,
        private readonly LegacyShiftMirror $mirror,
        private readonly ShiftCashCountService $counts,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    public function handle(ShiftEndedEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            // Serializes duplicate close projections on their source aggregate.
            $legacy = \Modules\Shift\Models\CashierShift::withoutEagerLoads()
                ->whereKey($event->shift->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->bridge($legacy);
        });
    }

    private function bridge(\Modules\Shift\Models\CashierShift $legacy): void
    {

        // A live mirror row opened at shift start is FINISHED here; anything
        // already past `active|late` was closed on the dashboard → skip.
        $mirrored = $this->mirror->existing($legacy->id);
        if ($mirrored !== null && ! in_array($mirrored->status, ['active', 'late'], true)) {
            return;
        }

        // Resolve the ASAB employee mirroring this legacy cashier.
        $employee = Employee::where('legacy_cashier_id', $legacy->cashier_id)->first();
        if ($employee === null || $employee->branch_id === null) {
            // no ASAB counterpart — nothing to review on the dashboard, but say
            // so (meeting 2026-07-29: closed shifts "never appeared" silently).
            $this->log->warning('shift-bridge: skipped — cashier has no mirrored ASAB employee', [
                'cashier_shift_id' => $legacy->id, 'cashier_id' => $legacy->cashier_id,
                'reason' => $employee === null ? 'CASHIER_NOT_MIRRORED' : 'EMPLOYEE_BRANCH_MISSING',
                'fix' => 'php artisan asab:mirror-mobile-cashiers, then php artisan asab:bridge-backfill',
            ]);

            return;
        }

        // The SHF op's branch_id comes from the employee, so a scoped accountant
        // only sees it if that branch carries asab_brand_id. Heal the tags from
        // the branch's restaurant link before minting the op, otherwise the shift
        // reaches the head (scope=all) but never the responsible accountant.
        $branch = Branch::whereKey($employee->branch_id)->first();
        if ($branch !== null) {
            $this->branches->ensure($branch);
        }

        // The system needs an ASAB user to attribute the operation to; use a
        // reviewer of the same company (accountant/head/admin). Without one the
        // bridge can't create a pipeline op, so it no-ops.
        $actor = $this->systemActor($employee->company_id);
        if ($actor === null) {
            $this->log->warning('shift-bridge: skipped — company has no ASAB user to attribute the operation to', [
                'cashier_shift_id' => $legacy->id, 'company_id' => $employee->company_id,
                'reason' => 'NO_ASAB_ACTOR',
            ]);

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

        // S1-10: the physical count of the shift's current report revision, when one exists. A shift
        // without it (historical, auto-closed, or edited without a re-count) keeps the Phase 1 legacy
        // projection below, labelled 'unknown': that projection is not a count and is not evidence.
        $count = $this->counts->currentFor($legacy->id);
        if ($count !== null) {
            $float = $count->confirmed_opening_halalas;
            $card = $count->cards_halalas;
            $aggregator = $count->apps_halalas;
        }

        $attributes = [
            'company_id' => $employee->company_id,
            'branch_id' => $employee->branch_id,
            'cashier_employee_id' => $employee->id,
            'cashier_name' => $employee->name,
            'started_at' => $legacy->actual_start_time ?? $legacy->created_at ?? now(),
            'status' => 'active',                 // close() expects an open shift
            'orders_count' => 0,
            'sales_amount' => $sales,
            'opening_float' => $float,
            'legacy_shift_id' => $legacy->id,
        ];

        if ($mirrored !== null) {
            // Keep the live row (and its id) so the board's shift becomes the
            // reviewed one instead of a duplicate appearing at close time.
            $mirrored->forceFill($attributes)->save();
            $shift = $mirrored;
        } else {
            $shift = Shift::create($attributes + ['shift_type' => $legacy->shift?->name ?? 'مسائي']);
        }

        $closeData = [
            'cardTotalHalalas' => $card,
            'aggregatorTotalsHalalas' => $aggregator,
            'aggregatorBreakdown' => $breakdown,
        ];
        if ($count !== null) {
            // Server-calculated figures from the stored count: counted cash is the drawer count, expected
            // uses only the confirmed opening, and the variance already excludes pending incoming cash.
            $closeData += [
                'cashActualHalalas' => $count->counted_halalas,
                'cashCountState' => 'counted',
                'countEvidence' => [
                    'expectedHalalas' => $count->expected_halalas,
                    'varianceHalalas' => $count->variance_halalas,
                    'pendingIncomingCountedHalalas' => $count->pending_incoming_counted_halalas,
                ],
            ];
        } else {
            // Preserve the Phase 1 mobile-to-Admin projection: cash_collected plus opening_balance. These
            // legacy fields are not an independent physical count or confirmed-opening evidence and must
            // not back LiabilityEvidenceSource.
            $closeData += [
                'cashActualHalalas' => $collected + $float,
                'cashCountState' => 'unknown',
            ];
        }

        $this->shifts->close($shift, $closeData, $actor, 'mobile');
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
        return ShiftFinancialCalculator::storedSarToHalalas($sar);
    }
}
