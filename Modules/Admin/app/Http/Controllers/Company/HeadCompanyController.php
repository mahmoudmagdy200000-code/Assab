<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ErpBatchService;

/**
 * Company-scoped Head Accountant surface (COMPANY_DASHBOARD_API_SPEC.md §5.2).
 * Operation list/approve/reject + ERP batches reuse the shared controllers;
 * this holds the head dashboard, accountants performance, and single post-to-erp.
 */
class HeadCompanyController extends AsabController
{
    public function __construct(private readonly ErpBatchService $erp) {}

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

            $pipeline = ['submit' => 0, 'review' => 0, 'approved' => 0, 'final' => 0, 'erp' => 0, 'reports' => 0, 'rejected' => 0];
            $pipeline['approved'] = $base()->where('status', Operation::STATUS_APPROVED)->count();
            $pipeline['final'] = $base()->where('status', Operation::STATUS_FINAL)->count();
            $pipeline['erp'] = $base()->where('erp_posted', true)->count();
            $pipeline['rejected'] = $base()->where('status', Operation::STATUS_REJECTED)->count();
            $pipeline['submit'] = $base()->where('status', Operation::STATUS_PENDING)->count();

            return $this->ok([
                'kpis' => [
                    'awaitingMyApprovalCount' => $pipeline['approved'],
                    'finalApprovedCount' => $pipeline['final'],
                    'erpPostedCount' => $pipeline['erp'],
                    'rejectedCount' => $pipeline['rejected'],
                    'monthlySalesHalalas' => $monthSales,
                    'salesDeltaPct' => $prevSales ? round(($monthSales - $prevSales) / $prevSales * 100, 1) : 0.0,
                ],
                'pipelineCounts' => $pipeline,
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
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $rows = \Modules\Admin\Models\AsabUserRole::where('role_key', 'accountant')
                ->whereHas('user', fn ($q) => $q->where('company_id', $companyId))->with('user')->get()
                ->map(function ($r) use ($companyId) {
                    $ops = Operation::where('company_id', $companyId)->where('approved_by_id', $r->user_id);
                    $thisMonth = (clone $ops)->whereBetween('approved_at', [now()->startOfMonth(), now()->endOfMonth()])->count();

                    return [
                        'userId' => $r->user_id, 'name' => $r->user?->name, 'opsThisMonth' => $thisMonth,
                        'pendingCount' => Operation::where('company_id', $companyId)->where('status', Operation::STATUS_PENDING)->count(),
                        'approvalRate' => 100, 'avgReviewMinutes' => 0,
                        'status' => $r->user?->status === 'active' ? 'active' : 'inactive',
                    ];
                })->all();

            return $this->listResponse($rows);
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
