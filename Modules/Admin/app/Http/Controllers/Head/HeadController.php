<?php

namespace Modules\Admin\Http\Controllers\Head;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ErpBatchService;

/**
 * Head Accountant (رئيس الحسابات) dashboard + ERP (BACKEND_API_SPEC.md §6.2).
 */
class HeadController extends AsabController
{
    public function __construct(private readonly ErpBatchService $erp) {}

    public function dashboard(): JsonResponse
    {
        return $this->run(function () {
            $byStatus = Operation::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
            $awaiting = (int) ($byStatus['approved'] ?? 0);
            $final = (int) ($byStatus['final-approved'] ?? 0);
            $rejected = (int) ($byStatus['rejected'] ?? 0);
            $erpPosted = Operation::where('erp_posted', true)->count();
            $total = max(1, array_sum($byStatus->all()));

            return $this->ok([
                'kpis' => [
                    'awaitingApproval' => $awaiting,
                    'finalApprovedAwaitingErp' => Operation::where('status', Operation::STATUS_FINAL)->where('erp_posted', false)->count(),
                    'erpPosted' => $erpPosted,
                    'rejected' => $rejected,
                    'performanceRate' => (int) round((($final + $erpPosted) / $total) * 100),
                ],
                'pipeline' => [
                    ['stageId' => 'review', 'count' => (int) ($byStatus['pending'] ?? 0)],
                    ['stageId' => 'approved', 'count' => $awaiting],
                    ['stageId' => 'final', 'count' => $final],
                    ['stageId' => 'erp', 'count' => $erpPosted],
                ],
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

    public function accountantsPerformance(): JsonResponse
    {
        return $this->run(function () {
            $accountants = AsabUser::whereHas('roleAssignments', fn ($r) => $r->where('role_key', 'accountant'))->get();

            $rows = $accountants->map(function ($a) {
                $reviewed = Operation::where('approved_by_id', $a->id)->count();
                $approved = Operation::where('approved_by_id', $a->id)->whereIn('status', ['approved', 'final-approved'])->count();
                $rate = $reviewed > 0 ? (int) round(($approved / $reviewed) * 100) : 0;

                return [
                    'id' => $a->id,
                    'name' => $a->name,
                    'reviewedCount' => $reviewed,
                    'approvedCount' => $approved,
                    'pendingCount' => Operation::where('status', 'pending')->count(),
                    'rate' => $rate,
                    'rating' => $rate >= 90 ? 5 : ($rate >= 75 ? 4 : 3),
                    'level' => $rate >= 90 ? 'ممتاز' : ($rate >= 75 ? 'جيد' : 'مقبول'),
                ];
            })->values()->all();

            return $this->ok([
                'accountants' => $rows,
                'overall' => [
                    'reviewed' => array_sum(array_column($rows, 'reviewedCount')),
                    'approved' => array_sum(array_column($rows, 'approvedCount')),
                    'pending' => Operation::where('status', 'pending')->count(),
                ],
            ]);
        });
    }

    public function conditionalApprove(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['conditionalNote' => 'required|string|max:500']);
            $op = Operation::where('id', $id)->orWhere('public_id', $id)->firstOrFail();
            $service = app(\Modules\Admin\Services\OperationService::class);

            return $this->ok($this->present($service->finalApprove($op, $request->user(), true, $data['conditionalNote'])));
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
