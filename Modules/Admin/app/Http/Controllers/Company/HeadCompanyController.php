<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
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
                ], $this->metrics->dashboardKpis($companyId)),
                'pipelineCounts' => $pipeline,
                'weeklyPerformance' => $this->metrics->weeklyPerformance($companyId),
                'brandPerformance' => AsabBrand::where('company_id', $companyId)->get()
                    ->map(fn (AsabBrand $b) => ['brandId' => $b->id, 'name' => $b->name, 'abbr' => $b->abbr, 'color' => $b->color, 'salesHalalas' => 0, 'pctOfTarget' => 0])->all(),
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

            $ops = $query->orderByDesc('operation_date')->get();

            return $this->ok([
                'data' => $ops->map(fn (Operation $o) => $this->presentOp($o))->all(),
                'meta' => [
                    'count' => $ops->count(),
                    'totalAmountHalalas' => (int) $ops->sum('amount'),
                    'branches' => $ops->pluck('branch_id')->filter()->unique()->count(),
                ],
            ]);
        });
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
            $batch = $this->erp->create(['operationIds' => [$op->id]], $request->user());

            return $this->ok(['batchId' => $batch->batch_id, 'queuedOpCount' => $batch->operation_count, 'totalHalalas' => $batch->total_amount]);
        });
    }
}
