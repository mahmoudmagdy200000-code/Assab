<?php

namespace Modules\Shift\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Illuminate\Support\Facades\Cache;

/**
 * ShiftFinancialService
 *
 * Handles all financial computations for branch-manager shifts:
 * totals, cashier breakdowns, daily-close summaries, and financial-value resolution.
 * Extracted from BranchManagerShiftService to keep that class within the 20-method limit.
 */
class ShiftFinancialService
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly BranchManagerShiftService $shiftService
    ) {}

    /**
     * Compute financial summary array from handovers (same shape as BranchManagerShiftResource::getFinancialSummary).
     */
    public function computeFinancialSummaryFromHandovers(BranchManagerShift $shift, Collection $shiftHandovers): array
    {
        if ($shift->total_sales > 0 || $shift->cash_collected > 0 || $shift->card_payments > 0) {
            return [
                'total_sales'         => (float) ($shift->total_sales ?? 0),
                'net_sales'           => (float) ($shift->net_sales ?? 0),
                'vat_amount'          => (float) ($shift->vat_amount ?? 0),
                'cash_collected'      => (float) ($shift->cash_collected ?? 0),
                'card_payments'       => (float) ($shift->card_payments ?? 0),
                'aggregator_payments' => (float) ($shift->aggregator_payments ?? 0),
                'total_variance'      => (float) ($shift->variance ?? 0),
            ];
        }

        $totalSales         = 0;
        $cashCollected      = 0;
        $cardPayments       = 0;
        $aggregatorPayments = 0;
        $totalVariance      = 0;

        foreach ($shiftHandovers as $handover) {
            $cashierShift        = $handover->cashierShift;
            $totalSales         += $cashierShift->total_sales ?? 0;
            $cashCollected      += $cashierShift->cash_collected ?? 0;
            $cardPayments       += $cashierShift->card_payments ?? 0;
            $aggregatorPayments += $cashierShift->salesBreakdown?->sum('amount') ?? 0;
            $totalVariance      += $handover->variance_amount ?? 0;
        }

        $vatAmount = $totalSales * 0.15;
        $netSales  = $totalSales - $vatAmount;

        return [
            'total_sales'         => (float) $totalSales,
            'net_sales'           => (float) $netSales,
            'vat_amount'          => (float) $vatAmount,
            'cash_collected'      => (float) $cashCollected,
            'card_payments'       => (float) $cardPayments,
            'aggregator_payments' => (float) $aggregatorPayments,
            'total_variance'      => (float) $totalVariance,
        ];
    }

    /**
     * Calculate financial summary with Redis-backed caching.
     */
    public function calculateFinancialSummary(BranchManagerShift $shift): array
    {
        $cacheKey = "shift:{$shift->id}:{$shift->shift_date->format('Y-m-d')}:financial_summary";

        if (config('cache.default') === 'redis') {
            $shiftTag = "shift:{$shift->id}:{$shift->shift_date->format('Y-m-d')}";
            try {
                return Cache::tags([$shiftTag])->remember($cacheKey, 300, fn () => $this->computeFinancialSummary($shift));
            } catch (\Exception $e) {
                // Fallback if tags not supported
            }
        }

        return Cache::remember($cacheKey, 300, fn () => $this->computeFinancialSummary($shift));
    }

    /**
     * Resolve the financial totals to be stored on the shift.
     * Request values take priority, then the computed financial summary, then existing model values.
     */
    public function resolveFinancialValues(Request $request, array $financialSummary, BranchManagerShift $managerShift): array
    {
        $totalSales         = (float) ($request->total_sales ?? $financialSummary['total_sales'] ?? $managerShift->total_sales ?? 0);
        $cashCollected      = (float) ($request->cash_collected ?? $financialSummary['cash_collected'] ?? $managerShift->cash_collected ?? 0);
        $cardPayments       = (float) ($request->card_payments ?? $financialSummary['card_payments'] ?? $managerShift->card_payments ?? 0);
        $aggregatorPayments = (float) ($request->aggregator_payments ?? $financialSummary['delivery_app_payments'] ?? $managerShift->aggregator_payments ?? 0);
        $vatAmount          = $totalSales * 0.15;

        return [
            'total_sales'         => $totalSales,
            'cash_collected'      => $cashCollected,
            'card_payments'       => $cardPayments,
            'aggregator_payments' => $aggregatorPayments,
            'vat_amount'          => $vatAmount,
            'net_sales'           => $totalSales - $vatAmount,
            'total_variance'      => (float) ($financialSummary['total_variance'] ?? 0),
        ];
    }

    /**
     * Build the per-cashier breakdown array from a collection of handovers.
     * Fetches delivery-app totals in a single aggregate query to avoid N+1.
     */
    public function buildCashierBreakdownFromHandovers(Collection $handovers): array
    {
        if ($handovers->isEmpty()) {
            return [];
        }

        $cashierShiftIds   = $handovers->pluck('cashier_shift_id')->toArray();
        $deliveryAppTotals = ShiftSalesBreakdown::whereIn('cashier_shift_id', $cashierShiftIds)
            ->selectRaw('cashier_shift_id, SUM(amount) as total_amount')
            ->groupBy('cashier_shift_id')
            ->pluck('total_amount', 'cashier_shift_id')
            ->toArray();

        return $handovers->map(function ($handover) use ($deliveryAppTotals) {
            $cashierShift = $handover->cashierShift;
            return [
                'cashier_name'          => $cashierShift->cashier->name,
                'cashier_id'            => $cashierShift->cashier_id,
                'cash_collected'        => (float) ($cashierShift->cash_collected ?? 0),
                'card_payments'         => (float) ($cashierShift->card_payments ?? 0),
                'delivery_app_payments' => (float) ($deliveryAppTotals[$cashierShift->id] ?? 0),
                'variance'              => (float) ($handover->variance_amount ?? 0),
                'sales'                 => (float) ($cashierShift->total_sales ?? 0),
            ];
        })->values()->all();
    }

    /**
     * Sum all approved handover amounts directed to a specific manager for a given shift.
     */
    public function sumApprovedHandoverAmount(BranchManagerShift $managerShift, string $managerId): float
    {
        // Same inclusion rules as handoffs list / daily close (shift_date OR handover_date window).
        $handovers = $this->shiftService->getShiftHandovers($managerShift, 'to_manager', true)
            ->filter(fn ($h) => (string) $h->handover_to_id === (string) $managerId);
        $sum = $handovers->where('status', 'approved')->sum(fn ($h) => (float) $h->handover_amount);

        return (float) ($sum ?: ($managerShift->closing_balance ?? 0));
    }

    /**
     * Prepare the final daily close summary (cashier breakdown + totals + manager summary).
     */
    public function prepareDailyCloseSummary(BranchManagerShift $shift): array
    {
        // Align with fetchShiftHandovers(to_manager): include cashier shifts tied to this workday via
        // shift_date OR via handover_date, so Summary is not empty when handoffs list shows approved items.
        $managerHandovers = $this->shiftService->getShiftHandovers($shift, 'to_manager', true);
        $idsFromHandovers = $managerHandovers->pluck('cashier_shift_id')->unique()->filter()->values();

        $idsSameDayBranch = CashierShift::query()
            ->whereDate('shift_date', $shift->shift_date)
            ->whereHas('shift', fn ($q) => $q->where('branch_id', $shift->branch_id))
            ->whereIn('status', [ShiftStatus::IN_PROGRESS->value, ShiftStatus::COMPLETED->value])
            ->pluck('id');

        $allCashierShiftIds = $idsFromHandovers->merge($idsSameDayBranch)->unique()->values();

        if ($allCashierShiftIds->isEmpty()) {
            $cashierShifts = collect();
            $handoversByShiftId = collect();
        } else {
            $cashierShifts = CashierShift::whereIn('id', $allCashierShiftIds)
                ->with(['cashier:id,name', 'salesBreakdown.aggregator:id,name', 'handover'])
                ->select(['id', 'cashier_id', 'shift_id', 'shift_date', 'total_sales', 'cash_collected', 'card_payments', 'variance', 'status', 'closing_balance'])
                ->get()
                ->sortBy(fn ($cs) => $cs->cashier->name ?? '')
                ->values();

            $handoversByShiftId = CashierShiftHandover::where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $shift->branch_manager_id)
                ->whereIn('cashier_shift_id', $allCashierShiftIds)
                ->get()
                ->keyBy('cashier_shift_id');
        }

        $cashierBreakdown = $cashierShifts->map(function ($cashierShift) use ($handoversByShiftId) {
            $deliveryApps = $cashierShift->salesBreakdown->sum('amount');
            $handover     = $handoversByShiftId->get($cashierShift->id);
            $variance     = $handover
                ? (float) ($handover->variance_amount ?? $cashierShift->variance ?? 0)
                : (float) ($cashierShift->variance ?? 0);

            $handoverAmount = $handover
                ? (float) ($handover->handover_amount ?? $cashierShift->closing_balance ?? 0)
                : (float) ($cashierShift->closing_balance ?? 0);

            return [
                'cashier_name'          => $cashierShift->cashier->name,
                'cashier_id'            => $cashierShift->cashier_id,
                'cash_collected'        => (float) ($cashierShift->cash_collected ?? 0),
                'card_payments'         => (float) ($cashierShift->card_payments ?? 0),
                'delivery_app_payments' => (float) $deliveryApps,
                'variance'              => $variance,
                'sales'                 => (float) ($cashierShift->total_sales ?? 0),
                'handover_amount'       => $handoverAmount,
                'handover_status'       => $handover?->status ?? 'not_submitted',
            ];
        })->values()->all();

        $totals = [
            'total_cash_collected' => (float) collect($cashierBreakdown)->sum('cash_collected'),
            'total_card_payments'  => (float) collect($cashierBreakdown)->sum('card_payments'),
            'total_delivery_apps'  => (float) collect($cashierBreakdown)->sum('delivery_app_payments'),
            'total_variance'       => (float) collect($cashierBreakdown)->sum('variance'),
            'total_sales'          => (float) collect($cashierBreakdown)->sum('sales'),
        ];

        $aggregators = $this->buildAggregatorsBreakdown($cashierShifts);

        $closingBalance  = $totals['total_cash_collected'];
        $expectedBalance = $totals['total_sales'];
        $calcVariance    = $expectedBalance - $closingBalance;

        if (!$shift->relationLoaded('branchManager')) {
            $shift->load('branchManager:id,name');
        }
        if (!$shift->relationLoaded('branch')) {
            $shift->load('branch:id,name');
        }

        return [
            'cashier_breakdown' => $cashierBreakdown,
            'aggregators'       => $aggregators,
            'totals'            => $totals,
            'manager_summary'   => [
                'opening_balance'  => (float) ($shift->opening_balance ?? 0),
                'closing_balance'  => (float) $closingBalance,
                'expected_balance' => (float) $expectedBalance,
                'variance'         => (float) $calcVariance,
                'variance_type'    => $this->shiftService->normalizeVarianceType($calcVariance),
            ],
            'shift_info' => [
                'date'    => $shift->shift_date->format('Y-m-d'),
                'manager' => $shift->branchManager->name,
                'branch'  => $shift->branch->name,
            ],
        ];
    }

    /**
     * Build the variance_details payload for a CashierShiftHandover response.
     */
    public function buildHandoverVarianceDetails(CashierShiftHandover $handover, CashierShift $cashierShift): ?array
    {
        if ($handover->variance_amount == 0) {
            return null;
        }

        $details = [
            'total_sales'         => (float) $cashierShift->total_sales,
            'handover_amount'     => (float) $handover->handover_amount,
            'variance_amount'     => (float) $handover->variance_amount,
            'variance_type'       => $this->shiftService->normalizeVarianceType((float) $handover->variance_amount),
            'reason_for_variance' => $handover->variance_reason,
            'attached_files'      => $handover->variance_files ?? [],
            'cashier_details'     => [
                'id'              => $cashierShift->cashier_id,
                'name'            => $cashierShift->cashier->name,
                'variance_reason' => $handover->variance_reason,
            ],
        ];

        if ($cashierShift->varianceDetails) {
            $details['other_cashiers'] = $cashierShift->varianceDetails->map(fn ($detail) => [
                'cashier_id'   => $detail->responsible_cashier_id,
                'cashier_name' => $detail->responsibleCashier?->name ?? 'External Factors',
                'amount'       => (float) $detail->assigned_amount,
                'notes'        => $detail->reason,
            ])->toArray();
        }

        return $details;
    }

    /**
     * Build the standardised daily_close_status response array.
     */
    public function buildDailyCloseStatusArray(BranchManagerShift $managerShift): array
    {
        return [
            'is_submitted'  => (bool) $managerShift->daily_report_submitted,
            'submitted_at'  => $managerShift->daily_report_submitted_at?->format(self::DATETIME_FORMAT),
            'notes'         => $managerShift->daily_report_notes,
            'can_submit'    => !$managerShift->daily_report_submitted,
            'can_reopen'    => $managerShift->can_reopen && $managerShift->daily_report_submitted,
            'reopened_at'   => $managerShift->reopened_at?->format(self::DATETIME_FORMAT),
            'reopen_reason' => $managerShift->reopen_reason,
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build per-aggregator breakdown for daily close (Delivery Apps: Jahez, Hunger Station, etc.).
     * Returns same cashier order as cashier_breakdown for consistent UI columns.
     */
    private function buildAggregatorsBreakdown(Collection $cashierShifts): array
    {
        $aggregatorMap = [];
        $cashierOrder  = $cashierShifts->pluck('cashier_id')->unique()->values()->all();
        $cashierNames  = $cashierShifts->keyBy('cashier_id')->map(fn ($cs) => $cs->cashier->name ?? '')->all();

        foreach ($cashierShifts as $cashierShift) {
            $cashierId   = $cashierShift->cashier_id;
            $cashierName = $cashierShift->cashier->name ?? ($cashierNames[$cashierId] ?? '');
            foreach ($cashierShift->salesBreakdown as $row) {
                $agg = $row->aggregator;
                if (!$agg) {
                    continue;
                }
                $id = $agg->id;
                $amount = (float) $row->amount;
                if (!isset($aggregatorMap[$id])) {
                    $aggregatorMap[$id] = [
                        'id'                => $id,
                        'name'              => $agg->name,
                        'total'             => 0.0,
                        'cashier_breakdown' => [],
                    ];
                }
                $aggregatorMap[$id]['total'] += $amount;
                if (!isset($aggregatorMap[$id]['cashier_breakdown'][$cashierId])) {
                    $aggregatorMap[$id]['cashier_breakdown'][$cashierId] = [
                        'cashier_id'   => $cashierId,
                        'cashier_name' => $cashierName,
                        'amount'       => 0.0,
                    ];
                }
                $aggregatorMap[$id]['cashier_breakdown'][$cashierId]['amount'] += $amount;
            }
        }

        $result = [];
        foreach ($aggregatorMap as $row) {
            $breakdown = [];
            foreach ($cashierOrder as $cid) {
                if (isset($row['cashier_breakdown'][$cid])) {
                    $breakdown[] = $row['cashier_breakdown'][$cid];
                } else {
                    $breakdown[] = [
                        'cashier_id'   => $cid,
                        'cashier_name' => $cashierNames[$cid] ?? '',
                        'amount'       => 0.0,
                    ];
                }
            }
            $result[] = [
                'id'                => $row['id'],
                'name'              => $row['name'],
                'total'             => (float) $row['total'],
                'cashier_breakdown' => array_values($breakdown),
            ];
        }

        return $result;
    }

    private function computeFinancialSummary(BranchManagerShift $shift): array
    {
        $handovers = $this->shiftService->getShiftHandovers($shift, 'to_manager', true);

        return $handovers->reduce(function ($summary, $handover) {
            $cashierShift = $handover->cashierShift;

            $summary['total_sales']           += $cashierShift->total_sales ?? 0;
            $summary['cash_collected']        += $cashierShift->cash_collected ?? 0;
            $summary['card_payments']         += $cashierShift->card_payments ?? 0;
            $summary['delivery_app_payments'] += $cashierShift->salesBreakdown->sum('amount');
            $summary['total_variance']        += $handover->variance_amount ?? 0;

            return $summary;
        }, [
            'total_sales'           => 0,
            'cash_collected'        => 0,
            'card_payments'         => 0,
            'delivery_app_payments' => 0,
            'total_variance'        => 0,
        ]);
    }
}
