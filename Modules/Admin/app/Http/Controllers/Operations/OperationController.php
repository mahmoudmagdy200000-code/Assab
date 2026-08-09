<?php

namespace Modules\Admin\Http\Controllers\Operations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ExpenseInvoiceService;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Services\PurchasePresenterService;
use Modules\Admin\Services\PurchaseReceivingBridge;
use Modules\Admin\Services\SalesReconciliationService;
use Modules\Admin\Support\OperationEnums;
use Modules\Branch\Models\Branch;

class OperationController extends AsabController
{
    public function __construct(
        private readonly OperationService $service,
        private readonly SalesReconciliationService $reconciler,
        private readonly ExpenseInvoiceService $invoices,
        private readonly PurchasePresenterService $purchases,
        private readonly PurchaseReceivingBridge $receiving,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = $this->scopeToAssignedBranches(Operation::query());

            foreach (['module_key' => 'moduleKey', 'status' => 'status', 'branch_id' => 'branchId', 'match' => 'match', 'origin' => 'origin'] as $col => $param) {
                if ($val = $request->query($param)) {
                    str_contains($val, ',')
                        ? $q->whereIn($col, explode(',', $val))
                        : $q->where($col, $val);
                }
            }
            if ($from = $request->query('dateFrom')) {
                $q->whereDate('operation_date', '>=', $from);
            }
            if ($to = $request->query('dateTo')) {
                $q->whereDate('operation_date', '<=', $to);
            }
            if ($search = $request->query('search')) {
                $q->where('public_id', 'like', "%{$search}%");
            }
            // Meeting 2026-07-29: «فلترة بحسب العلامة التجارية» — brandId narrows
            // to the branches tagged with that brand; ANDs with the caller's
            // assigned-branch scope, so it can only narrow, never widen.
            $this->applyBrandFilter($q, $request->query('brandId'));
            // ACC-3.2 purchases-only filters (payload-keyed).
            if ($supplierId = $request->query('supplierId')) {
                $q->where('payload->supplierId', $supplierId);
            }

            $p = $q->orderByDesc('operation_date')->orderByDesc('created_at')
                ->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            $items = $p->items();
            // ACC-3 order-source filter is derived, so it is applied post-load on
            // the page (documented FE caveat: filters a page, not the full set).
            if ($source = $request->query('source')) {
                $items = array_values(array_filter($items, fn ($o) => $o->module_key === 'purchases'
                    && $this->purchases->orderSource($o)['key'] === $source));
            }

            $supplierNames = $this->supplierNamesFor($p->items());
            $maps = $this->opDisplayMaps($items);

            return $this->paginated(
                $p,
                array_map(fn ($o) => $this->present($o, $supplierNames, $maps), $items),
                ['summary' => $this->summary($request)],
            );
        });
    }

    /**
     * Supplier names for a page of operations in one query (no N+1). Keyed by
     * supplier id; empty for a page with no purchases rows.
     *
     * @return array<string, string>
     */
    private function supplierNamesFor(array $ops): array
    {
        $ids = collect($ops)
            ->filter(fn ($o) => $o->module_key === 'purchases')
            ->map(fn ($o) => $o->payload['supplierId'] ?? null)
            ->filter()->unique()->values();

        return $ids->isEmpty()
            ? []
            : \Modules\Admin\Models\AsabSupplier::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    public function show(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $op = $this->scopeToAssignedBranches(
                Operation::with('steps')->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id)),
            )->firstOrFail();
            $data = $this->present($op, [], $this->opDisplayMaps([$op]));
            $data['payload'] = $op->payload;
            // ACC-1.4 «جدول المقارنة والتسوية» — computed, never stored.
            if ($op->module_key === 'sales') {
                $data['reconciliation'] = $this->reconciler->present($op);
            }
            // ACC-2.2 the statement's invoices with their VAT split and توثيق stamps.
            if ($op->module_key === 'expenses') {
                $data['expenses'] = $this->invoices->present($op);
            }
            // ACC-3.3 the 3-way match table + summary tiles + attachments.
            if ($op->module_key === 'purchases') {
                $data['purchases'] = $this->purchases->present($op, $this->receiving->receivedByRow($op));
            }
            $data['auditTrail'] = $op->steps->map(fn ($s) => [
                'stageId' => $s->stage_id,
                'action' => $s->action,
                'by' => $s->actor_label,
                'time' => optional($s->occurred_at)->toIso8601String(),
                'note' => $s->note,
            ])->all();

            return $this->ok($data);
        });
    }

    /**
     * GET /operations/{id}/attachments — the ACC-1.4 attachments panel
     * (POS report, bank statement, aggregator sheets…).
     */
    public function attachments(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $op = $this->find($id);

            $rows = Attachment::where('owner_id', $op->id)
                ->orderBy('uploaded_at')
                ->get()
                ->map(fn (Attachment $a) => [
                    'id' => $a->id,
                    'filename' => $a->filename,
                    'mimeType' => $a->mime_type,
                    'size' => $a->size,
                    'publicUrl' => $a->public_url,
                    'label' => $a->label,
                    'verifiedAt' => optional($a->verified_at)->toIso8601String(),
                    'uploadedAt' => optional($a->uploaded_at)->toIso8601String(),
                ])->all();

            return $this->listResponse($rows, ['total' => count($rows)]);
        });
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->present(
            $this->service->approve($this->find($id), $request->user(), $request->input('note')),
        )));
    }

    /**
     * POST /operations/{id}/reject — return the record to the branch manager.
     * `reason` is a key from GET /lookups/rejection-reasons (SRS §5.4); the raw
     * Arabic label is still accepted for one release. `details`/`notes` carry
     * the optional free text.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate([
                'reason' => 'required|string|max:500',
                'notes' => 'sometimes|nullable|string|max:1000',
                'details' => 'sometimes|nullable|string|max:1000',
            ]);

            return $this->ok($this->present(
                $this->service->reject(
                    $this->find($id),
                    $request->user(),
                    $data['reason'],
                    $data['details'] ?? $data['notes'] ?? null,
                ),
            ));
        });
    }

    /**
     * POST /operations/{id}/request-clarification — «طلب توضيح».
     * Non-terminal: asks the submitter for information, status is unchanged.
     */
    public function requestClarification(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['message' => 'required|string|max:500']);

            return $this->ok($this->present(
                $this->service->requestClarification($this->find($id), $request->user(), $data['message']),
            ));
        });
    }

    /**
     * POST /operations/{id}/document — «توثيق» (ACC-3.4).
     * The accountant marks the purchase order documented before it reaches the
     * head. Idempotent; status unchanged; 409 on a locked op.
     */
    public function document(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['note' => 'sometimes|nullable|string|max:1000']);

            return $this->ok($this->present(
                $this->service->document($this->find($id), $request->user(), $data['note'] ?? null),
            ));
        });
    }

    /**
     * POST /operations/{id}/final-approve — head accountant final approval.
     * Conditional approval is the `isConditional` flag form (FE completion
     * request §1.6); the standalone conditional-approve endpoint was retired.
     */
    public function finalApprove(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate([
                'isConditional' => 'sometimes|boolean',
                'conditionalNote' => 'required_if:isConditional,true|nullable|string|max:1000',
                'conditions' => 'sometimes|array',
                'conditions.*.id' => 'sometimes|string',
                'conditions.*.text' => 'required_with:conditions|string|max:500',
                'conditions.*.dueAt' => 'sometimes|nullable|date',
            ]);

            return $this->ok($this->present(
                $this->service->finalApprove(
                    $this->find($id),
                    $request->user(),
                    (bool) ($data['isConditional'] ?? false),
                    $data['conditionalNote'] ?? null,
                    $data['conditions'] ?? [],
                ),
            ));
        });
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['operationIds' => 'required|array', 'operationIds.*' => 'string']);

            // Zero-trust: drop ids outside the caller's assigned branch scope
            // before they reach the approval service.
            $ids = $data['operationIds'];
            $allowed = $this->scopeToAssignedBranches(
                Operation::query()->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('public_id', $ids)),
            )->pluck('id')->all();

            return $this->ok($this->service->bulkApprove($allowed, $request->user()));
        });
    }

    /**
     * POST /operations/bulk-final-approve — HEAD-2.1/2.4 «اعتماد الكل». Head-only
     * group final approval of the approved queue. Same zero-trust branch filter
     * as bulkApprove; supports the conditional flag applied to every op.
     */
    public function bulkFinalApprove(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'operationIds' => 'required|array',
                'operationIds.*' => 'string',
                'isConditional' => 'sometimes|boolean',
                'conditionalNote' => 'required_if:isConditional,true|nullable|string|max:1000',
            ]);

            $ids = $data['operationIds'];
            $allowed = $this->scopeToAssignedBranches(
                Operation::query()->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('public_id', $ids)),
            )->pluck('id')->all();

            return $this->ok($this->service->bulkFinalApprove(
                $allowed, $request->user(),
                (bool) ($data['isConditional'] ?? false), $data['conditionalNote'] ?? null,
            ));
        });
    }

    /**
     * POST /operations/{id}/return-for-review — HEAD-2.1 «إرجاع للمراجعة».
     * Head sends an approved op back to the accountant queue. 409 otherwise.
     */
    public function returnForReview(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['note' => 'sometimes|nullable|string|max:1000']);

            return $this->ok($this->present(
                $this->service->returnForReview($this->find($id), $request->user(), $data['note'] ?? null),
            ));
        });
    }

    /** POST /operations/bulk-return — group «إرجاع للمراجعة». */
    public function bulkReturn(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'operationIds' => 'required|array',
                'operationIds.*' => 'string',
                'note' => 'sometimes|nullable|string|max:1000',
            ]);

            $ids = $data['operationIds'];
            $allowed = $this->scopeToAssignedBranches(
                Operation::query()->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('public_id', $ids)),
            )->pluck('id')->all();

            return $this->ok($this->service->bulkReturnForReview($allowed, $request->user(), $data['note'] ?? null));
        });
    }

    public function correction(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            // FE completion request §1.5 body: { reason, notes?, correctedFields? }.
            // Legacy callers send { correctionReason, amount, diffNote } — accept both.
            $data = $request->validate([
                'reason' => 'sometimes|string|max:500',
                'correctionReason' => 'sometimes|string|max:500',
                'notes' => 'sometimes|nullable|string|max:1000',
                'correctedFields' => 'sometimes|array',
                'amount' => 'sometimes|integer|min:0',
                'diffNote' => 'sometimes|string|max:255',
            ]);

            $reason = $data['reason'] ?? $data['correctionReason'] ?? null;
            if ($reason === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'reason' => ['The reason field is required.'],
                ]);
            }
            if (isset($data['notes'])) {
                $reason = trim($reason.' — '.$data['notes']);
            }

            $fields = $data['correctedFields'] ?? [];
            $original = $this->find($id);
            $overrides = array_filter([
                'amount' => $data['amount'] ?? ($fields['amount'] ?? null),
                'diff_note' => $data['diffNote'] ?? ($fields['diffNote'] ?? null),
            ], fn ($v) => $v !== null);

            $correction = $this->service->correction($original, $request->user(), $reason, $overrides);

            // FE completion request §1.5 — return the linkage, not the full operation.
            return $this->created([
                'originalOperationId' => $original->id,
                'correctionOperationId' => $correction->id,
                'publicId' => $correction->public_id,
                'status' => $correction->status,
                'createdAt' => optional($correction->created_at)->toIso8601String(),
            ]);
        });
    }

    public function auditTrail(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $op = $this->scopeToAssignedBranches(
                Operation::with('steps')->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id)),
            )->firstOrFail();
            $last = $op->steps->count() - 1;

            return $this->listResponse($op->steps->values()->map(fn ($s, $i) => [
                'icon' => $this->stageIcon($s->stage_id),
                'stageId' => $s->stage_id,
                'action' => $s->action,
                'by' => $s->actor_label,
                'time' => optional($s->occurred_at)->toIso8601String(),
                'note' => $s->note,
                'isTerminal' => in_array($s->stage_id, ['final', 'rejected'], true) && $i === $last,
            ])->all());
        });
    }

    private function stageIcon(string $stage): string
    {
        return OperationEnums::stageIcon($stage);
    }

    private function find(string $id): Operation
    {
        return $this->scopeToAssignedBranches(
            Operation::where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id)),
        )->firstOrFail();
    }

    /**
     * brandId → the brand's branch ids. Resolved through the shared resolver:
     * a branch linked to the brand only by its restaurant carries a NULL
     * `asab_brand_id`, and filtering on that column alone answered «no
     * branches» — the whole brand-filtered list came back empty (2026-08-03).
     */
    private function applyBrandFilter($query, ?string $brandId): void
    {
        app(\Modules\Admin\Services\BrandBranchResolver::class)->applyFilter($query, $brandId);
    }

    private function summary(Request $request): array
    {
        $base = $this->scopeToAssignedBranches(Operation::query());
        if ($module = $request->query('moduleKey')) {
            $base->where('module_key', $module);
        }
        $this->applyBrandFilter($base, $request->query('brandId'));

        $summary = [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', Operation::STATUS_PENDING)->count(),
            'approved' => (clone $base)->where('status', Operation::STATUS_APPROVED)->count(),
            'finalApproved' => (clone $base)->where('status', Operation::STATUS_FINAL)->count(),
            'rejected' => (clone $base)->where('status', Operation::STATUS_REJECTED)->count(),
        ];

        // ACC-3.1 purchases KPI header — only on the purchases tab.
        if ($request->query('moduleKey') === 'purchases') {
            $today = now()->toDateString();
            $summary['purchases'] = [
                'todayTotalHalalas' => (int) (clone $base)->whereDate('operation_date', $today)->sum('amount'),
                'pendingReview' => $summary['pending'],
                'qtyDiscrepancies' => (clone $base)->whereIn('status', [Operation::STATUS_PENDING, Operation::STATUS_APPROVED])
                    ->where('match', 'diff')->count(),
                'approvedToday' => (clone $base)->where('status', Operation::STATUS_APPROVED)
                    ->whereDate('operation_date', $today)->count(),
            ];
        }

        return $summary;
    }

    /**
     * Enum fields stay bare keys (`status: "approved"`) for back-compat; the
     * canonical Arabic label of each rides alongside as `*LabelAr` so no screen
     * re-implements a label map (SRS §5, master-plan cross-cutting rule).
     */
    /**
     * Branch + brand display names for a page of operations in two batched
     * queries (meeting 2026-07-30: rows showed bare UUIDs / empty الفرع
     * والعلامة التجارية). Same shape as HeadController::buildOpMaps.
     *
     * @param  array<int, Operation>  $ops
     * @return array{branches: array, brands: array}
     */
    private function opDisplayMaps(array $ops): array
    {
        $collection = collect($ops);
        if ($collection->isEmpty()) {
            return ['branches' => [], 'brands' => []];
        }

        $branches = Branch::whereIn('id', $collection->pluck('branch_id')->filter()->unique())
            ->get(['id', 'name', 'asab_brand_id'])->keyBy('id');

        $brands = \Modules\Admin\Models\AsabBrand::whereIn('id', $branches->pluck('asab_brand_id')->filter()->unique())
            ->get(['id', 'name'])->pluck('name', 'id');

        return [
            'branches' => $branches->map(fn ($b) => ['name' => $b->name, 'brandId' => $b->asab_brand_id])->all(),
            'brands' => $brands->all(),
        ];
    }

    private function present(Operation $op, array $supplierNames = [], array $maps = []): array
    {
        $status = OperationEnums::status($op->status);
        $origin = OperationEnums::origin($op->origin);
        $match = OperationEnums::match($op->match);
        $branch = $maps['branches'][$op->branch_id] ?? null;
        $brandId = $branch['brandId'] ?? null;

        return [
            'id' => $op->id,
            'publicId' => $op->public_id,
            'branchId' => $op->branch_id,
            'branchName' => $branch['name'] ?? null,
            'brandId' => $brandId,
            'brandName' => $brandId ? ($maps['brands'][$brandId] ?? null) : null,
            'date' => optional($op->operation_date)->toDateString(),
            'moduleKey' => $op->module_key,
            'sourceModule' => $op->source_module,
            'sourceId' => $op->source_id,
            'amount' => $op->amount,
            // The head rows already carry this alias; the accountant surface did
            // not, and the dashboard prints a bare `amount` verbatim — so the
            // same figure read 100× too large depending on which screen you were
            // on (prod E2E 2026-07-31).
            'amountHalalas' => (int) $op->amount,
            'match' => $op->match,
            'matchLabelAr' => $match['labelAr'],
            'diffNote' => $op->diff_note,
            'origin' => $op->origin,
            'originLabelAr' => $origin['labelAr'],
            'originIcon' => $origin['icon'],
            'attachmentCount' => $op->attachment_count,
            'status' => $op->status,
            'statusLabelAr' => $status['labelAr'],
            'statusLabelShortAr' => $status['labelShortAr'],
            'stage' => OperationEnums::stageFor($op->status, (bool) $op->erp_posted),
            'rejectReason' => $op->reject_reason,
            'rejectReasonKey' => OperationEnums::rejectionReasonKey($op->reject_reason, $op->module_key),
            'isConditional' => (bool) $op->is_conditional,
            'isCorrection' => (bool) $op->is_correction,
            'erpPosted' => (bool) $op->erp_posted,
            'operationDate' => optional($op->operation_date)->toIso8601String(),
            'submittedAt' => optional($op->submitted_at)->toIso8601String(),
            'approvedAt' => optional($op->approved_at)->toIso8601String(),
            'finalApprovedAt' => optional($op->final_approved_at)->toIso8601String(),
            'createdAt' => optional($op->created_at)->toIso8601String(),
            // ACC-1.3 matching table columns; null until the op is reconciled.
            'salesBreakdown' => $op->module_key === 'sales' ? $this->reconciler->breakdown($op) : null,
            // ACC-3.2 purchases list-row projection; null for other modules.
            'purchaseRow' => $op->module_key === 'purchases'
                ? $this->purchases->row($op, $supplierNames[$op->payload['supplierId'] ?? ''] ?? null)
                : null,
        ];
    }
}
