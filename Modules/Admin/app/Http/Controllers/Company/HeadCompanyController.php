<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ErpBatchService;
use Modules\Admin\Services\HeadMetricsService;

/**
 * Company-scoped Head Accountant surface (COMPANY_DASHBOARD_API_SPEC.md §5.2).
 * Operation list/approve/reject + ERP batches reuse the shared controllers;
 * this holds the head dashboard, accountants performance, and single post-to-erp.
 */
class HeadCompanyController extends AsabController
{
    public function __construct(
        private readonly ErpBatchService $erp,
        private readonly HeadMetricsService $metrics,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $from = $request->query('dateFrom', now()->startOfMonth()->toDateString());
            $to = $request->query('dateTo', now()->endOfMonth()->toDateString()).' 23:59:59';

            $base = fn () => Operation::where('company_id', $companyId);
            $monthSales = (int) $base()->where('module_key', 'sales')->whereBetween('operation_date', [$from, $to])->sum('amount');
            $prevFrom = now()->subMonth()->startOfMonth()->toDateString();
            $prevTo = now()->subMonth()->endOfMonth()->toDateString().' 23:59:59';
            $prevSales = (int) $base()->where('module_key', 'sales')->whereBetween('operation_date', [$prevFrom, $prevTo])->sum('amount');

            // Map our 4 statuses onto the spec's funnel stages: a freshly-submitted
            // op sits in the accountant "review" queue (pending) until approved → head "final".
            $pending = $base()->where('status', Operation::STATUS_PENDING)->count();
            $pipeline = [
                'submit' => $pending,
                'review' => $pending,
                'approved' => $base()->where('status', Operation::STATUS_APPROVED)->count(),
                'final' => $base()->where('status', Operation::STATUS_FINAL)->where('erp_posted', false)->count(),
                'erp' => $base()->where('erp_posted', true)->count(),
                'reports' => 0,
                'rejected' => $base()->where('status', Operation::STATUS_REJECTED)->count(),
            ];

            return $this->ok([
                // Existing fields kept; enriched per MISSING_Dashboard §4.1.
                'kpis' => array_merge([
                    'awaitingMyApprovalCount' => $pipeline['approved'],
                    'finalApprovedCount' => $pipeline['final'],
                    'erpPostedCount' => $pipeline['erp'],
                    'rejectedCount' => $pipeline['rejected'],
                    'monthlySalesHalalas' => $monthSales,
                    'salesDeltaPct' => $prevSales ? round(($monthSales - $prevSales) / $prevSales * 100, 1) : 0.0,
                    'accountantsActive' => $this->metrics->accountantsActive($companyId),
                ], $this->metrics->dashboardKpis($companyId)),
                'pipelineCounts' => $pipeline,
                'weeklyPerformance' => $this->metrics->weeklyPerformance($companyId),
                // HEAD-1.3 real brand performance (was hardcoded zeros).
                'brandPerformance' => $this->metrics->brandPerformance($companyId),
                'awaitingFinalApprovalPreview' => $base()->where('status', Operation::STATUS_APPROVED)
                    ->orderByDesc('approved_at')->limit(5)->get()
                    ->map(fn (Operation $o) => ['id' => $o->id, 'publicId' => $o->public_id, 'moduleKey' => $o->module_key, 'amount' => $o->amount, 'branchId' => $o->branch_id])->all(),
            ]);
        });
    }

    public function accountantsPerformance(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->metrics->accountantsPerformance(
            $request->user()->company_id,
            $request->query('dateFrom'),
            $request->query('dateTo'),
        )));
    }

    /** GET /company/me/head/movements/recent?limit=10 (MISSING_Dashboard §4.3). */
    public function movementsRecent(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $limit = min(50, max(1, (int) $request->query('limit', 10)));

            return $this->listResponse($this->metrics->recentMovements($request->user()->company_id, $limit));
        });
    }

    /**
     * GET /company/me/erp/preview — eligible-ops preview honoring all filters
     * (MISSING_Dashboard "Head ERP preview"). Reuses ErpBatchService::eligible
     * for the default final-approved/not-posted set, then layers restaurant,
     * period (today/week/month/custom) and an optional status override on top.
     */
    public function erpPreview(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;

            $data = $request->validate([
                'moduleKey' => 'sometimes|nullable|string',
                'restaurantId' => 'sometimes|nullable|string',
                'branchId' => 'sometimes|nullable|string',
                'status' => 'sometimes|nullable|string|in:pending,approved,rejected,final-approved',
                'period' => 'sometimes|nullable|array',
                'period.type' => 'sometimes|nullable|string|in:today,week,month,custom',
                'period.from' => 'sometimes|nullable|date',
                'period.to' => 'sometimes|nullable|date',
            ]);

            [$dateFrom, $dateTo] = $this->resolvePeriod($data['period'] ?? []);
            $statusOverride = $data['status'] ?? null;

            // Shared filter map understood by ErpBatchService::eligible().
            $filters = array_filter([
                'moduleKey' => $data['moduleKey'] ?? null,
                'branchId' => $data['branchId'] ?? null,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ], fn ($v) => $v !== null && $v !== '');

            if ($statusOverride) {
                // Status override: build the base query directly (eligible() pins
                // final-approved/not-posted), then reuse the same filter handling.
                $query = Operation::where('company_id', $companyId)->where('status', $statusOverride);
                $this->applyEligibleFilters($query, $filters);
            } else {
                // Default: final-approved & not yet ERP-posted, via the service.
                $query = $this->erp->eligible(['filters' => $filters]);
            }

            // Restaurant scope → resolve to that restaurant's branch ids.
            if (! empty($data['restaurantId'])) {
                $branchIds = \Modules\Branch\Models\Branch::where('asab_company_id', $companyId)
                    ->where('asab_restaurant_id', $data['restaurantId'])
                    ->pluck('id')->all();
                $query->whereIn('branch_id', $branchIds ?: ['__none__']);
            }

            // T10.10 — bounded: paginate rows; totals + per-restaurant breakdown
            // are computed by aggregate queries, not by loading every op.
            $perPage = min((int) $request->query('pageSize', 50), 100);
            $p = (clone $query)->orderByDesc('operation_date')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->ok([
                'data' => collect($p->items())->map(fn (Operation $o) => $this->presentOp($o))->all(),
                'meta' => [
                    'page' => $p->currentPage(),
                    'pageSize' => $p->perPage(),
                    'total' => $p->total(),
                    'totalPages' => $p->lastPage(),
                    'count' => (clone $query)->count(),
                    'totalAmountHalalas' => (int) (clone $query)->sum('amount'),
                    'branches' => (clone $query)->distinct()->count('branch_id'),
                    'perRestaurant' => $this->perRestaurant(clone $query, $companyId),
                ],
            ]);
        });
    }

    /**
     * ERP-3 per-restaurant breakdown of a preview selection. One grouped query on
     * the ops (by branch) + one branch→restaurant lookup — bounded by branch count.
     *
     * @return array<int, array{restaurantId:?string, name:?string, count:int, amountHalalas:int}>
     */
    private function perRestaurant($query, string $companyId): array
    {
        $byBranch = $query->selectRaw('branch_id, count(*) as c, sum(amount) as a')->groupBy('branch_id')->get();
        if ($byBranch->isEmpty()) {
            return [];
        }
        $branches = \Modules\Branch\Models\Branch::whereIn('id', $byBranch->pluck('branch_id')->filter())
            ->get(['id', 'asab_restaurant_id'])->keyBy('id');
        $restaurantIds = $branches->pluck('asab_restaurant_id')->filter()->unique();
        $names = $restaurantIds->isEmpty()
            ? collect()
            : \Modules\Admin\Models\AsabRestaurant::whereIn('id', $restaurantIds)->pluck('name', 'id');

        $agg = [];
        foreach ($byBranch as $row) {
            $restaurantId = optional($branches->get($row->branch_id))->asab_restaurant_id;
            $key = $restaurantId ?? '__none__';
            $agg[$key] ??= ['restaurantId' => $restaurantId, 'name' => $restaurantId ? ($names[$restaurantId] ?? null) : null, 'count' => 0, 'amountHalalas' => 0];
            $agg[$key]['count'] += (int) $row->c;
            $agg[$key]['amountHalalas'] += (int) $row->a;
        }

        return array_values($agg);
    }

    /**
     * Derive [dateFrom, dateTo] (Y-m-d / Y-m-d H:i:s) from the period filter.
     * type=today|week|month use the current window; custom honors from/to.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolvePeriod(array $period): array
    {
        $type = $period['type'] ?? null;

        return match ($type) {
            'today' => [now()->startOfDay()->toDateTimeString(), now()->endOfDay()->toDateTimeString()],
            'week' => [now()->startOfWeek()->toDateTimeString(), now()->endOfWeek()->toDateTimeString()],
            'month' => [now()->startOfMonth()->toDateTimeString(), now()->endOfMonth()->toDateTimeString()],
            'custom' => [
                ! empty($period['from']) ? \Illuminate\Support\Carbon::parse($period['from'])->startOfDay()->toDateTimeString() : null,
                ! empty($period['to']) ? \Illuminate\Support\Carbon::parse($period['to'])->endOfDay()->toDateTimeString() : null,
            ],
            default => [
                ! empty($period['from']) ? \Illuminate\Support\Carbon::parse($period['from'])->startOfDay()->toDateTimeString() : null,
                ! empty($period['to']) ? \Illuminate\Support\Carbon::parse($period['to'])->endOfDay()->toDateTimeString() : null,
            ],
        };
    }

    /** Apply the same module/branch/date filters ErpBatchService::eligible uses. */
    private function applyEligibleFilters($query, array $filters): void
    {
        if (! empty($filters['moduleKey']) && $filters['moduleKey'] !== 'all') {
            $query->where('module_key', $filters['moduleKey']);
        }
        if (! empty($filters['branchId'])) {
            $query->where('branch_id', $filters['branchId']);
        }
        if (! empty($filters['dateFrom'])) {
            $query->where('operation_date', '>=', $filters['dateFrom']);
        }
        if (! empty($filters['dateTo'])) {
            $query->where('operation_date', '<=', $filters['dateTo']);
        }
    }

    /** Spec-shaped operation row, mirroring HeadController::present. */
    private function presentOp(Operation $op): array
    {
        return [
            'id' => $op->id,
            'publicId' => $op->public_id,
            'branchId' => $op->branch_id,
            'moduleKey' => $op->module_key,
            'amount' => $op->amount,
            'match' => $op->match,
            'status' => $op->status,
            'rejectReason' => $op->reject_reason,
            'erpPosted' => (bool) $op->erp_posted,
            'operationDate' => optional($op->operation_date)->toIso8601String(),
        ];
    }

    public function postToErp(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $op = Operation::where('company_id', $request->user()->company_id)
                ->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))->firstOrFail();
            $batches = $this->erp->export(['operationIds' => [$op->id]], $request->user());
            $first = $batches->first();

            return $this->ok([
                'batchId' => $first?->batch_id,
                'queuedOpCount' => (int) $batches->sum('operation_count'),
                'totalHalalas' => (int) $batches->sum('total_amount'),
            ]);
        });
    }
}
