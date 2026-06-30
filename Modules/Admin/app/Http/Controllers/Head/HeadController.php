<?php

namespace Modules\Admin\Http\Controllers\Head;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ErpBatchService;
use Modules\Admin\Services\HeadMetricsService;
use Modules\Branch\Models\Branch;

/**
 * Head Accountant (رئيس الحسابات) dashboard + ERP (BACKEND_API_SPEC.md §6.2).
 */
class HeadController extends AsabController
{
    /** Module display labels (ar/en) shared by operation rows + internal reports. */
    private const MODULE_LABELS = [
        'sales' => ['ar' => 'المبيعات', 'en' => 'Sales'],
        'expenses' => ['ar' => 'المصروفات', 'en' => 'Expenses'],
        'purchases' => ['ar' => 'المشتريات', 'en' => 'Purchases'],
        'inventory' => ['ar' => 'المخزون', 'en' => 'Inventory'],
        'waste' => ['ar' => 'الهدر', 'en' => 'Waste'],
        'assets' => ['ar' => 'الأصول', 'en' => 'Assets'],
        'shifts' => ['ar' => 'الورديات', 'en' => 'Shifts'],
        'employees' => ['ar' => 'الموظفين', 'en' => 'Employees'],
        'cash' => ['ar' => 'النقدية', 'en' => 'Cash'],
    ];

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
            $finalAwaitingErp = Operation::where('status', Operation::STATUS_FINAL)->where('erp_posted', false)->count();

            return $this->ok([
                // Existing fields kept; enriched per MISSING_Dashboard §4.1.
                // `awaiting`/`finalApproved` are FE-contract aliases (B-H2) for the
                // longer keys, kept alongside them so older readers don't break.
                'kpis' => array_merge([
                    'awaiting' => $awaiting,
                    'awaitingApproval' => $awaiting,
                    'finalApproved' => $finalAwaitingErp,
                    'finalApprovedAwaitingErp' => $finalAwaitingErp,
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

            // Report "cards": per-module summary + a working download link (the
            // head operations export blob, filtered by module) — B-H5.
            return $this->listResponse($byModule->map(fn ($r) => [
                'moduleKey' => $r->module_key,
                'labelAr' => self::moduleLabel($r->module_key, 'ar'),
                'labelEn' => self::moduleLabel($r->module_key, 'en'),
                'count' => (int) $r->cnt,
                'total' => (int) $r->total,
                'downloadUrl' => '/api/v1/operations/export?moduleKey='.$r->module_key,
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
            $net = $sales - $expenses - $purchases;

            // Real baseline (B-H5): net position posted this month vs last month,
            // so the FE drops its hardcoded September constants.
            $thisMonthNet = $this->postedNet(now()->startOfMonth(), now());
            $prevStart = now()->subMonthNoOverflow()->startOfMonth();
            $prevNet = $this->postedNet($prevStart, now()->startOfMonth()->subSecond());
            $netPctChange = $prevNet !== 0 ? round((($thisMonthNet - $prevNet) / abs($prevNet)) * 100, 1) : null;

            return $this->ok([
                'period' => ['label' => now()->format('Y-m'), 'from' => now()->startOfMonth()->toIso8601String(), 'to' => now()->toIso8601String()],
                'headline' => [
                    'netPosition' => $net,
                    'salesPosted' => $sales,
                    'expensesPosted' => $expenses,
                    'purchasesPosted' => $purchases,
                    'currentMonthNet' => $thisMonthNet,
                    'previousMonthNet' => $prevNet,
                    'netPctChange' => $netPctChange,
                ],
                'categoryBreakdown' => [
                    ['key' => 'sales', 'label' => 'المبيعات', 'isIncome' => true, 'amount' => $sales],
                    ['key' => 'expenses', 'label' => 'المصروفات', 'isIncome' => false, 'amount' => $expenses],
                    ['key' => 'purchases', 'label' => 'المشتريات', 'isIncome' => false, 'amount' => $purchases],
                ],
            ]);
        });
    }

    /** Net (sales − expenses − purchases) of ERP-posted ops within a window. */
    private function postedNet(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): int
    {
        $sumFor = fn (string $module) => (int) Operation::where('erp_posted', true)
            ->where('module_key', $module)
            ->whereBetween('erp_posted_at', [$from, $to])->sum('amount');

        return $sumFor('sales') - $sumFor('expenses') - $sumFor('purchases');
    }

    public function erpPreflight(): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->erp->preflight()));
    }

    public function erpEligible(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $ops = $this->erp->eligible(['filters' => $request->query()])->get();
            $maps = $this->buildOpMaps($ops->all());

            return $this->ok([
                'operations' => $ops->map(fn ($o) => $this->present($o, $maps))->all(),
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
                'createdAt' => optional($batch->created_at)->toIso8601String(),
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
                'createdAt' => optional($b->created_at)->toIso8601String(),
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
            $maps = $this->buildOpMaps($p->items());

            return $this->paginated($p, array_map(fn ($o) => $this->present($o, $maps), $p->items()), [
                'summary' => [
                    'count' => $p->total(),
                    'totalAmount' => (int) (clone $q)->sum('amount'),
                ],
            ]);
        });
    }

    /**
     * Batch-resolve the accountant/branch/brand display data for a page of
     * operations in a few queries (no per-row N+1) so present() can enrich each
     * row with names the Head UI needs (B-H1). The Operation row only stores
     * branch_id + actor ids; brand is reached through the branch.
     *
     * @param  array<int, Operation>  $ops
     * @return array{accountants:array, branches:array, brands:array}
     */
    private function buildOpMaps(array $ops): array
    {
        $collection = collect($ops);
        if ($collection->isEmpty()) {
            return ['accountants' => [], 'branches' => [], 'brands' => []];
        }

        $accountantIds = $collection
            ->map(fn ($o) => $o->approved_by_id ?: ($o->reviewed_by_id ?: $o->submitted_by_id))
            ->filter()->unique()->values();

        $branches = Branch::whereIn('id', $collection->pluck('branch_id')->filter()->unique())
            ->get(['id', 'name', 'asab_brand_id'])->keyBy('id');

        $brands = AsabBrand::whereIn('id', $branches->pluck('asab_brand_id')->filter()->unique())
            ->get(['id', 'name'])->pluck('name', 'id');

        return [
            'accountants' => AsabUser::whereIn('id', $accountantIds)->get(['id', 'name'])->pluck('name', 'id')->all(),
            'branches' => $branches->map(fn ($b) => ['name' => $b->name, 'brandId' => $b->asab_brand_id])->all(),
            'brands' => $brands->all(),
        ];
    }

    /**
     * @param  array{accountants:array, branches:array, brands:array}  $maps
     */
    private function present(Operation $op, array $maps = []): array
    {
        $accountantId = $op->approved_by_id ?: ($op->reviewed_by_id ?: $op->submitted_by_id);
        $branch = $maps['branches'][$op->branch_id] ?? null;
        $brandId = $branch['brandId'] ?? null;

        return [
            'id' => $op->id,
            'publicId' => $op->public_id,
            'accountantId' => $accountantId,
            'accountantName' => $accountantId ? ($maps['accountants'][$accountantId] ?? null) : null,
            'brandId' => $brandId,
            'brandName' => $brandId ? ($maps['brands'][$brandId] ?? null) : null,
            'branchId' => $op->branch_id,
            'branchName' => $branch['name'] ?? null,
            'moduleKey' => $op->module_key,
            'moduleLabel' => self::moduleLabel($op->module_key, 'ar'),
            'amount' => $op->amount,
            'amountHalalas' => $op->amount,
            'match' => $op->match,
            'status' => $op->status,
            'rejectReason' => $op->reject_reason,
            'erpPosted' => (bool) $op->erp_posted,
            'erpBatchId' => $op->erp_batch_id,
            'operationDate' => optional($op->operation_date)->toIso8601String(),
        ];
    }

    private static function moduleLabel(?string $key, string $lang = 'ar'): ?string
    {
        if (! $key) {
            return null;
        }

        return self::MODULE_LABELS[$key][$lang] ?? $key;
    }
}
