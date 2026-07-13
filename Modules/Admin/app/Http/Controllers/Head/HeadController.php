<?php

namespace Modules\Admin\Http\Controllers\Head;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Attachment;
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
                    'accountantsActive' => $this->metrics->accountantsActive($companyId),
                ], $this->metrics->dashboardKpis($companyId)),
                'pipeline' => [
                    ['stageId' => 'submit', 'count' => (int) ($byStatus['pending'] ?? 0)],
                    ['stageId' => 'review', 'count' => (int) ($byStatus['pending'] ?? 0)],
                    ['stageId' => 'approved', 'count' => $awaiting],
                    ['stageId' => 'final', 'count' => $final],
                    ['stageId' => 'erp', 'count' => $erpPosted],
                    ['stageId' => 'reports', 'count' => 0],
                ],
                'brandPerformance' => $this->metrics->brandPerformance($companyId),
                'weeklyPerformance' => $this->metrics->weeklyPerformance($companyId),
            ]);
        });
    }

    public function pending(Request $request): JsonResponse
    {
        if ($request->query('view') === 'grouped') {
            return $this->groupedPending($request);
        }

        return $this->listByStatus($request, Operation::STATUS_APPROVED);
    }

    /**
     * HEAD-2.1 grouped final-approval queue: approved ops grouped by
     * accountant × module, each group carrying count/total + a «⚠ يوجد فروق»
     * flag and a capped preview of its ops.
     */
    private function groupedPending(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $ops = $this->filteredOps($request, Operation::STATUS_APPROVED)
                ->orderByDesc('updated_at')->limit(2000)->get();
            $maps = $this->buildOpMaps($ops->all());
            $cap = min((int) $request->query('groupPreview', 5), 20);

            $groups = $ops->groupBy(fn (Operation $o) => ($o->approved_by_id ?: '—').'|'.$o->module_key)
                ->map(function ($groupOps) use ($maps, $cap) {
                    $first = $groupOps->first();
                    $accountantId = $first->approved_by_id;

                    return [
                        'accountantId' => $accountantId,
                        'accountantName' => $accountantId ? ($maps['accountants'][$accountantId] ?? null) : null,
                        'moduleKey' => $first->module_key,
                        'moduleLabelAr' => self::moduleLabel($first->module_key, 'ar'),
                        'count' => $groupOps->count(),
                        'totalAmount' => (int) $groupOps->sum('amount'),
                        'hasDiffs' => $groupOps->contains(fn (Operation $o) => $o->match === 'diff'),
                        'operations' => $groupOps->take($cap)->map(fn (Operation $o) => $this->present($o, $maps))->values()->all(),
                        'moreCount' => max(0, $groupOps->count() - $cap),
                    ];
                })->values()->all();

            return $this->ok([
                'groups' => $groups,
                'summary' => [
                    'count' => $ops->count(),
                    'totalAmount' => (int) $ops->sum('amount'),
                    'groupCount' => count($groups),
                ],
            ]);
        });
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
            // HEAD-7 financial-report cards ride in meta (additive — existing FE
            // reads `data`; the two new cards are `meta.financialReports`).
            return $this->listResponse(
                $byModule->map(fn ($r) => [
                    'moduleKey' => $r->module_key,
                    'labelAr' => self::moduleLabel($r->module_key, 'ar'),
                    'labelEn' => self::moduleLabel($r->module_key, 'en'),
                    'count' => (int) $r->cnt,
                    'total' => (int) $r->total,
                    'downloadUrl' => '/api/v1/operations/export?moduleKey='.$r->module_key,
                ])->all(),
                ['financialReports' => [
                    [
                        'key' => 'pl-by-brand',
                        'labelAr' => 'قائمة الدخل لكل علامة',
                        'labelEn' => 'Income Statement by Brand',
                        'reportKey' => 'pl',
                        'method' => 'POST',
                        'endpoint' => '/api/v1/reports/profit-loss',
                        'formats' => ['json', 'pdf', 'xlsx'],
                        'note' => 'مرّر brandIds للحصول على قائمة دخل لكل علامة تجارية',
                    ],
                    [
                        'key' => 'branch-compare',
                        'labelAr' => 'مقارنة الفروع',
                        'labelEn' => 'Branch Comparison',
                        'reportKey' => 'pl',
                        'method' => 'POST',
                        'endpoint' => '/api/v1/reports/profit-loss',
                        'formats' => ['json'],
                        'note' => 'الأداء الفعلي لكل فرع عبر branchIds؛ المقارنة بالمستهدف تُركّب من المستهدف الشهري في نظرة الفرع (v1)',
                    ],
                ]],
            );
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
            // T10.10 — bounded: paginate rows, compute totals by query (not by
            // loading every eligible op into memory).
            $builder = $this->erp->eligible(['filters' => $request->query()]);
            $perPage = min((int) $request->query('pageSize', 50), 100);
            $p = (clone $builder)->orderByDesc('operation_date')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));
            $maps = $this->buildOpMaps($p->items());

            return $this->paginated($p, array_map(fn ($o) => $this->present($o, $maps), $p->items()), [
                'total' => [
                    'count' => (clone $builder)->count(),
                    'amount' => (int) (clone $builder)->sum('amount'),
                    'branches' => (clone $builder)->distinct()->count('branch_id'),
                ],
                // Legacy alias key (older FE reads `operations`).
                'operations' => array_map(fn ($o) => $this->present($o, $maps), $p->items()),
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
            // Splits into one batch per (day × module) and posts each.
            $batches = $this->erp->export($data, $request->user());
            $first = $batches->first();

            return $this->created([
                'batches' => $batches->map(fn ($b) => $this->erp->present($b))->all(),
                'count' => $batches->count(),
                'totalAmountHalalas' => (int) $batches->sum('total_amount'),
                // Back-compat: first-batch fields at top level (single-group case).
                'id' => $first?->id,
                'batchId' => $first?->batch_id,
                'status' => $first?->status,
                'createdAt' => optional($first?->created_at)->toIso8601String(),
            ]);
        });
    }

    public function erpBatches(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            // ErpBatch now carries BelongsToTenant → company-isolated for the head.
            $q = \Modules\Admin\Models\ErpBatch::query();
            if ($module = $request->query('moduleKey')) {
                $q->where('module_key', $module);
            }
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            if ($from = $request->query('dateFrom')) {
                $q->whereDate('batch_date', '>=', $from);
            }
            if ($to = $request->query('dateTo')) {
                $q->whereDate('batch_date', '<=', $to);
            }
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $p = $q->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map(fn ($b) => $this->erp->present($b), $p->items()));
        });
    }

    private function listByStatus(Request $request, string $status): JsonResponse
    {
        return $this->run(function () use ($request, $status) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = $this->filteredOps($request, $status);
            // Sum BEFORE paginate() — paginate mutates the builder with limit/offset,
            // so a clone taken afterwards sums an empty window past page 1.
            $totalAmount = (int) (clone $q)->sum('amount');
            $p = $q->orderByDesc('updated_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));
            $maps = $this->buildOpMaps($p->items());

            return $this->paginated($p, array_map(fn ($o) => $this->present($o, $maps), $p->items()), [
                'summary' => [
                    'count' => $p->total(),
                    'totalAmount' => $totalAmount,
                ],
            ]);
        });
    }

    /**
     * HEAD-2.2 shared filter for the head operation lists: status + module,
     * erpPosted, brand (via branch→brand), accountant (approved_by), and an
     * operation-date window.
     */
    private function filteredOps(Request $request, string $status)
    {
        $q = Operation::where('status', $status);
        if ($module = $request->query('moduleKey')) {
            $q->where('module_key', $module);
        }
        if ($request->filled('erpPosted')) {
            $q->where('erp_posted', $request->boolean('erpPosted'));
        }
        if ($brandId = $request->query('brandId')) {
            $q->whereIn('branch_id', Branch::where('asab_brand_id', $brandId)->pluck('id'));
        }
        if ($accountantId = $request->query('accountantId')) {
            $q->where('approved_by_id', $accountantId);
        }
        if ($from = $request->query('dateFrom')) {
            $q->whereDate('operation_date', '>=', $from);
        }
        if ($to = $request->query('dateTo')) {
            $q->whereDate('operation_date', '<=', $to);
        }

        return $q;
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
            return ['accountants' => [], 'branches' => [], 'brands' => [], 'attachments' => []];
        }

        $accountantIds = $collection
            ->map(fn ($o) => $o->approved_by_id ?: ($o->reviewed_by_id ?: $o->submitted_by_id))
            ->filter()->unique()->values();

        $branches = Branch::whereIn('id', $collection->pluck('branch_id')->filter()->unique())
            ->get(['id', 'name', 'asab_brand_id'])->keyBy('id');

        $brands = AsabBrand::whereIn('id', $branches->pluck('asab_brand_id')->filter()->unique())
            ->get(['id', 'name'])->pluck('name', 'id');

        // Attachment counts per operation in one grouped query (B-H2 rows show a
        // paperclip badge without an N+1 per row).
        $attachments = Attachment::whereIn('owner_id', $collection->pluck('id')->filter()->unique())
            ->selectRaw('owner_id, count(*) as c')->groupBy('owner_id')->pluck('c', 'owner_id')->all();

        return [
            'accountants' => AsabUser::whereIn('id', $accountantIds)->get(['id', 'name'])->pluck('name', 'id')->all(),
            'branches' => $branches->map(fn ($b) => ['name' => $b->name, 'brandId' => $b->asab_brand_id])->all(),
            'brands' => $brands->all(),
            'attachments' => $attachments,
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
            'attachmentCount' => (int) ($maps['attachments'][$op->id] ?? 0),
            'diffNote' => data_get($op->payload, 'diffNote') ?? data_get($op->payload, 'varianceNotes'),
            'submittedAt' => optional($op->submitted_at)->toIso8601String(),
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
