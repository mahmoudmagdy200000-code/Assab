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
