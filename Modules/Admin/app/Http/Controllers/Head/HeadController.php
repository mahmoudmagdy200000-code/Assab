<?php

namespace Modules\Admin\Http\Controllers\Head;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ErpBatchService;
use Modules\Admin\Services\HeadMetricsService;

/**
 * Head Accountant (رئيس الحسابات) dashboard + ERP (BACKEND_API_SPEC.md §6.2).
 */
class HeadController extends AsabController
{
    public function __construct(
        private readonly ErpBatchService $erp,
        private readonly HeadMetricsService $metrics,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $byStatus = Operation::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
            $awaiting = (int) ($byStatus['approved'] ?? 0);
            $final = (int) ($byStatus['final-approved'] ?? 0);
            $rejected = (int) ($byStatus['rejected'] ?? 0);
            $erpPosted = Operation::where('erp_posted', true)->count();

            return $this->ok([
                // Existing fields kept; enriched per MISSING_Dashboard §4.1.
                'kpis' => array_merge([
                    'awaitingApproval' => $awaiting,
                    'finalApprovedAwaitingErp' => Operation::where('status', Operation::STATUS_FINAL)->where('erp_posted', false)->count(),
                    'erpPosted' => $erpPosted,
                    'rejected' => $rejected,
                ], $this->metrics->dashboardKpis($companyId)),
                'pipeline' => [
                    ['stageId' => 'review', 'count' => (int) ($byStatus['pending'] ?? 0)],
                    ['stageId' => 'approved', 'count' => $awaiting],
                    ['stageId' => 'final', 'count' => $final],
                    ['stageId' => 'erp', 'count' => $erpPosted],
                ],
                'weeklyPerformance' => $this->metrics->weeklyPerformance($companyId),
            ]);
        });
    }

    public function pending(Request $request): JsonResponse
    {
        return $this->listByStatus($request, Operation::STATUS_APPROVED);
    }

    public function finalApproved(Request $request): JsonResponse
    {
        return $this->listByStatus($request, Operation::STATUS_FINAL);
    }

    public function rejected(Request $request): JsonResponse
    {
        return $this->listByStatus($request, Operation::STATUS_REJECTED);
    }

    public function accountantsPerformance(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->metrics->accountantsPerformance(
            $request->user()->company_id,
            $request->query('dateFrom'),
            $request->query('dateTo'),
        )));
    }

    /** GET /head/movements/recent?limit=10 (MISSING_Dashboard §4.3). */
    public function movementsRecent(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $limit = min(50, max(1, (int) $request->query('limit', 10)));

            return $this->listResponse($this->metrics->recentMovements($request->user()->company_id, $limit));
        });
    }

    public function reportsInternal(Request $request): JsonResponse
    {
        return $this->run(function () {
            $byModule = Operation::query()
                ->selectRaw('module_key, count(*) as cnt, sum(amount) as total')
                ->groupBy('module_key')->get();

            return $this->listResponse($byModule->map(fn ($r) => [
                'moduleKey' => $r->module_key,
                'count' => (int) $r->cnt,
                'total' => (int) $r->total,
            ])->all());
        });
    }

    public function reportsOwner(Request $request): JsonResponse
    {
        return $this->run(function () {
            $posted = Operation::where('erp_posted', true);
            $sales = (int) (clone $posted)->where('module_key', 'sales')->sum('amount');
            $expenses = (int) (clone $posted)->where('module_key', 'expenses')->sum('amount');
            $purchases = (int) (clone $posted)->where('module_key', 'purchases')->sum('amount');

            return $this->ok([
                'period' => ['label' => now()->format('Y-m'), 'from' => now()->startOfMonth()->toIso8601String(), 'to' => now()->toIso8601String()],
                'headline' => [
                    'netPosition' => $sales - $expenses - $purchases,
                    'salesPosted' => $sales,
                    'expensesPosted' => $expenses,
                    'purchasesPosted' => $purchases,
                    'netPctChange' => null,
                ],
                'categoryBreakdown' => [
                    ['key' => 'sales', 'label' => 'المبيعات', 'isIncome' => true, 'amount' => $sales],
                    ['key' => 'expenses', 'label' => 'المصروفات', 'isIncome' => false, 'amount' => $expenses],
                    ['key' => 'purchases', 'label' => 'المشتريات', 'isIncome' => false, 'amount' => $purchases],
                ],
            ]);
        });
    }

    public function erpPreflight(): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->erp->preflight()));
    }

    public function erpEligible(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $ops = $this->erp->eligible(['filters' => $request->query()])->get();

            return $this->ok([
                'operations' => $ops->map(fn ($o) => $this->present($o))->all(),
                'total' => [
                    'count' => $ops->count(),
                    'amount' => (int) $ops->sum('amount'),
                    'branches' => $ops->pluck('branch_id')->unique()->count(),
                ],
            ]);
        });
    }

    public function erpCreateBatch(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'operationIds' => 'sometimes|array',
                'filters' => 'sometimes|array',
            ]);
            $batch = $this->erp->create($data, $request->user());

            return $this->created([
                'id' => $batch->id,
                'batchId' => $batch->batch_id,
                'operationCount' => $batch->operation_count,
                'totalAmount' => $batch->total_amount,
                'status' => $batch->status,
                'startedAt' => optional($batch->started_at)->toIso8601String(),
            ]);
        });
    }

    public function erpBatches(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $p = \Modules\Admin\Models\ErpBatch::orderByDesc('created_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map(fn ($b) => [
                'id' => $b->id,
                'batchId' => $b->batch_id,
                'operationCount' => $b->operation_count,
                'totalAmount' => $b->total_amount,
                'status' => $b->status,
                'completedAt' => optional($b->completed_at)->toIso8601String(),
            ], $p->items()));
        });
    }

    private function listByStatus(Request $request, string $status): JsonResponse
    {
        return $this->run(function () use ($request, $status) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = Operation::where('status', $status);
            if ($module = $request->query('moduleKey')) {
                $q->where('module_key', $module);
            }
            if ($request->filled('erpPosted')) {
                $q->where('erp_posted', $request->boolean('erpPosted'));
            }
            $p = $q->orderByDesc('updated_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map(fn ($o) => $this->present($o), $p->items()), [
                'summary' => [
                    'count' => $p->total(),
                    'totalAmount' => (int) (clone $q)->sum('amount'),
                ],
            ]);
        });
    }

    private function present(Operation $op): array
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
}
