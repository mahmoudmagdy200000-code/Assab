<?php

namespace Modules\Admin\Http\Controllers\Operations;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // BD-2 / BD-3 read-only shortage liability allocation, manager approval status,
            // report revision number, and allocation version for shift operations.
            // FIN-01 / COR-05 confirmed opening, cash collected, handover notes, and correction attribution.
            if ($op->module_key === 'shifts') {
                $cashierShiftId = $this->resolveCashierShiftId($op);
                $liability = $this->presentShiftLiability($op, $cashierShiftId);
                $reportRevision = $this->resolveShiftReportRevision($op, $cashierShiftId);
                $allocationVersion = $liability !== null ? (int) $liability['version'] : null;
                $confirmedOpeningHalalas = $this->resolveConfirmedOpeningHalalas($op, $cashierShiftId);
                $shiftFields = $this->resolveCashierShiftFields($op, $cashierShiftId);
                $lastCorrection = $this->resolveShiftLastCorrection($op, $reportRevision);

                $data['reportRevision'] = $reportRevision;
                $data['allocationVersion'] = $allocationVersion;
                $data['liability'] = $liability;
                $data['confirmedOpeningHalalas'] = $confirmedOpeningHalalas;

                if ($shiftFields !== null) {
                    $data['cashCollectedHalalas'] = $shiftFields['cashCollectedHalalas'];
                    $data['handoverNotes'] = $shiftFields['handoverNotes'];
                }
                if ($lastCorrection !== null) {
                    $data['lastCorrection'] = $lastCorrection;
                }

                if (is_array($data['payload'])) {
                    $data['payload']['reportRevision'] = $reportRevision;
                    $data['payload']['allocationVersion'] = $allocationVersion;
                    $data['payload']['liability'] = $liability;
                    $data['payload']['confirmedOpeningHalalas'] = $confirmedOpeningHalalas;
                    $data['payload']['confirmed_opening_halalas'] = $confirmedOpeningHalalas;

                    if ($shiftFields !== null) {
                        $data['payload']['cashCollectedHalalas'] = $shiftFields['cashCollectedHalalas'];
                        $data['payload']['cash_collected_halalas'] = $shiftFields['cashCollectedHalalas'];
                        $data['payload']['cash_collected'] = $shiftFields['cashCollected'];
                        $data['payload']['handoverNotes'] = $shiftFields['handoverNotes'];
                        $data['payload']['handover_notes'] = $shiftFields['handoverNotes'];
                    }
                    if ($lastCorrection !== null) {
                        $data['payload']['lastCorrection'] = $lastCorrection;
                    }
                }
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
                // Role-aware: the head rejecting an ALREADY-approved record
                // returns it to the accountant instead of closing it (SRS §5.4
                // + meeting 2026-08-14).
                $this->service->rejectAs(
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
            return ['branches' => [], 'brands' => [], 'actors' => []];
        }

        $branches = Branch::whereIn('id', $collection->pluck('branch_id')->filter()->unique())
            ->get(['id', 'name', 'asab_brand_id'])->keyBy('id');

        $brands = \Modules\Admin\Models\AsabBrand::whereIn('id', $branches->pluck('asab_brand_id')->filter()->unique())
            ->get(['id', 'name'])->pluck('name', 'id');

        // Who decided — the rows printed «مرفوضة» / «معتمدة» with no name, and
        // the meeting asks for the deciding accountant's / head's name on the
        // record («وتتكتب اسم رئيس الحسابات»). One query for the whole page.
        $actorIds = $collection->flatMap(fn ($o) => [
            $o->approved_by_id, $o->final_approved_by_id, $o->rejected_by_id,
        ])->filter()->unique();

        $actors = $actorIds->isEmpty()
            ? collect()
            : \Modules\Admin\Models\AsabUser::whereIn('id', $actorIds)->get(['id', 'name'])->pluck('name', 'id');

        return [
            'branches' => $branches->map(fn ($b) => ['name' => $b->name, 'brandId' => $b->asab_brand_id])->all(),
            'brands' => $brands->all(),
            'actors' => $actors->all(),
        ];
    }

    /**
     * «من اتخذ القرار» — the last decision on the record, whichever world it
     * came from. A brand owner deciding in the mobile app is not an `asab_users`
     * row, so their name rides in the bridged payload instead of an actor id.
     *
     * @return array{name: ?string, role: ?string, roleLabelAr: ?string}|null
     */
    private function decisionBy(Operation $op, array $actors): ?array
    {
        $legacy = $op->payload['legacyDecision'] ?? null;
        if (is_array($legacy) && ($legacy['byName'] ?? null) !== null) {
            return [
                'name' => $legacy['byName'],
                'role' => $legacy['byRole'] ?? null,
                'roleLabelAr' => $legacy['byRoleLabelAr'] ?? null,
            ];
        }

        [$actorId, $role, $roleLabelAr] = match ($op->status) {
            Operation::STATUS_FINAL => [$op->final_approved_by_id, 'head', 'رئيس الحسابات'],
            Operation::STATUS_REJECTED => [$op->rejected_by_id, null, null],
            Operation::STATUS_APPROVED => [$op->approved_by_id, 'accountant', 'المحاسب'],
            default => [null, null, null],
        };

        if ($actorId === null) {
            return null;
        }

        return ['name' => $actors[$actorId] ?? null, 'role' => $role, 'roleLabelAr' => $roleLabelAr];
    }

    private function present(Operation $op, array $supplierNames = [], array $maps = []): array
    {
        $status = OperationEnums::status($op->status);
        $origin = OperationEnums::origin($op->origin);
        $match = OperationEnums::match($op->match);
        $branch = $maps['branches'][$op->branch_id] ?? null;
        $brandId = $branch['brandId'] ?? null;
        $actors = $maps['actors'] ?? [];

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
            'reportReview' => $op->payload['pkg6Review'] ?? null,
            'status' => $op->status,
            'statusLabelAr' => $status['labelAr'],
            'statusLabelShortAr' => $status['labelShortAr'],
            'stage' => OperationEnums::stageFor($op->status, (bool) $op->erp_posted),
            'rejectReason' => $op->reject_reason,
            'rejectReasonKey' => OperationEnums::rejectionReasonKey($op->reject_reason, $op->module_key),
            'approvedBy' => $op->approved_by_id ? ($actors[$op->approved_by_id] ?? null) : null,
            'finalApprovedBy' => $op->final_approved_by_id ? ($actors[$op->final_approved_by_id] ?? null) : null,
            'rejectedBy' => $op->rejected_by_id ? ($actors[$op->rejected_by_id] ?? null) : null,
            'decisionBy' => $this->decisionBy($op, $actors),
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

    /**
     * Resolve the validated cashier shift ID for an operation.
     * Enforces strict branch and tenant provenance: the cashier shift MUST
     * belong to the same company and branch as the operation.
     */
    private function resolveCashierShiftId(Operation $op): ?string
    {
        if (! empty($op->payload['legacyShiftId'])) {
            $legacyId = (string) $op->payload['legacyShiftId'];

            return $this->isValidCashierShiftForOperation($legacyId, $op) ? $legacyId : null;
        }

        $shiftId = $op->payload['shiftId'] ?? $op->source_id;
        if ($shiftId) {
            $legacyFromAdmin = DB::table('asab_shifts')
                ->where('id', $shiftId)
                ->where('company_id', $op->company_id)
                ->where('branch_id', $op->branch_id)
                ->value('legacy_shift_id');
            if ($legacyFromAdmin && $this->isValidCashierShiftForOperation((string) $legacyFromAdmin, $op)) {
                return (string) $legacyFromAdmin;
            }
        }

        if ($op->source_id && $this->isValidCashierShiftForOperation((string) $op->source_id, $op)) {
            return (string) $op->source_id;
        }

        return null;
    }

    /**
     * Verify that a cashier shift strictly belongs to the operation's company and branch.
     */
    private function isValidCashierShiftForOperation(string $cashierShiftId, Operation $op): bool
    {
        $branchInfo = DB::table('cashier_shifts')
            ->join('shifts', 'shifts.id', '=', 'cashier_shifts.shift_id')
            ->join('branches', 'branches.id', '=', 'shifts.branch_id')
            ->where('cashier_shifts.id', $cashierShiftId)
            ->select('shifts.branch_id', 'branches.asab_company_id')
            ->first();

        if ($branchInfo !== null) {
            return (string) $branchInfo->branch_id === (string) $op->branch_id
                && (string) $branchInfo->asab_company_id === (string) $op->company_id;
        }

        return DB::table('asab_shifts')
            ->where('legacy_shift_id', $cashierShiftId)
            ->where('company_id', $op->company_id)
            ->where('branch_id', $op->branch_id)
            ->exists();
    }

    /**
     * BD-3 — Resolve the confirmed shift report revision number.
     * Derived from ShiftReportAggregate (current_revision_number) or recorded revisions.
     * Enforces provenance: only revisions belonging to this shift's aggregate are trusted.
     * Returns null if no report revision exists without inventing arbitrary defaults.
     */
    private function resolveShiftReportRevision(Operation $op, ?string $cashierShiftId = null): ?int
    {
        $cashierShiftId ??= $this->resolveCashierShiftId($op);
        if (! $cashierShiftId) {
            return null;
        }

        $aggregate = DB::table('shift_report_aggregates')
            ->where('source_type', 'cashier_shift')
            ->where('source_id', $cashierShiftId)
            ->first();

        if (! $aggregate) {
            return null;
        }

        if ((int) $aggregate->current_revision_number > 0) {
            return (int) $aggregate->current_revision_number;
        }

        $maxRev = DB::table('shift_report_revisions')
            ->where('report_aggregate_id', $aggregate->id)
            ->max('revision_number');
        if ($maxRev !== null && (int) $maxRev > 0) {
            return (int) $maxRev;
        }

        if (isset($op->payload['pkg6Review']['revision_id'])) {
            $revNumber = DB::table('shift_report_revisions')
                ->where('id', $op->payload['pkg6Review']['revision_id'])
                ->where('report_aggregate_id', $aggregate->id)
                ->value('revision_number');
            if ($revNumber !== null && (int) $revNumber > 0) {
                return (int) $revNumber;
            }
        }

        return null;
    }

    /**
     * BD-2 / BD-3 — Expose read-only shortage liability allocation and manager approval status
     * for shift operations (moduleKey = shifts).
     *
     * Enforces tenant and branch isolation and resolves archived/inactive employee names
     * per MEM-01..06 without reactivating membership or shifting liability.
     *
     * @return array{version: int, managerApprovalStatus: ?string, createdByType: ?string, shares: array<int, array{type: string, id: ?string, name: ?string, amountHalalas: int}>}|null
     */
    private function presentShiftLiability(Operation $op, ?string $cashierShiftId = null): ?array
    {
        $cashierShiftId ??= $this->resolveCashierShiftId($op);
        if (! $cashierShiftId) {
            return null;
        }

        $allocation = DB::table('shift_liability_allocations')
            ->where('cashier_shift_id', $cashierShiftId)
            ->where('company_id', $op->company_id)
            ->where('branch_id', $op->branch_id)
            ->whereNull('superseded_at')
            ->orderByDesc('version')
            ->first();

        if (! $allocation) {
            return null;
        }

        $rawShares = DB::table('shift_liability_shares')
            ->where('allocation_id', $allocation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $shares = [];
        foreach ($rawShares as $share) {
            $name = $this->resolveResponsibleActorName(
                $share->responsible_type,
                $share->responsible_id,
                $op->company_id,
                $op->branch_id
            );

            $shares[] = [
                'type' => $share->responsible_type,
                'id' => $name !== null ? (string) $share->responsible_id : null,
                'name' => $name,
                'amountHalalas' => (int) $share->amount_halalas,
            ];
        }

        return [
            'version' => (int) $allocation->version,
            'managerApprovalStatus' => $allocation->manager_approval_status,
            'createdByType' => $allocation->created_by_type,
            'shares' => $shares,
        ];
    }

    /**
     * Resolve the recorded name of a responsible actor within branch scope.
     * Uses withTrashed() to preserve archived/terminated employee records (MEM-06)
     * without reactivating membership or modifying status.
     */
    private function resolveResponsibleActorName(string $type, string $id, string $companyId, string $branchId): ?string
    {
        return match ($type) {
            'cashier' => \Modules\Cashier\Models\Cashier::withTrashed()
                ->where('id', $id)
                ->where('branch_id', $branchId)
                ->value('name'),

            'branch_manager' => \Modules\BranchManagers\Models\BranchManager::withTrashed()
                ->where('id', $id)
                ->where('branch_id', $branchId)
                ->value('name'),

            'employee' => \Modules\Admin\Models\Employee::withTrashed()
                ->where('id', $id)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->value('name'),

            default => null,
        };
    }

    /**
     * FIN-01 — Project confirmed opening evidence (in halalas) from the cashier shift's
     * confirmed cash-count/receipt evidence. Enforces tenant, branch, and revision association.
     * Never derives from configured float. Returns null if no confirmed evidence exists.
     */
    private function resolveConfirmedOpeningHalalas(Operation $op, ?string $cashierShiftId = null): ?int
    {
        $cashierShiftId ??= $this->resolveCashierShiftId($op);
        if (! $cashierShiftId) {
            return null;
        }

        // 1. If a cash count exists for the current revision, use its stored confirmed_opening_halalas
        $count = app(\Modules\Shift\Services\ShiftCashCountService::class)->currentFor($cashierShiftId);
        if ($count !== null) {
            return (int) $count->confirmed_opening_halalas;
        }

        // 2. If confirmed receipts exist in cashier_shift_handover_receipts, sum them
        $hasReceipts = DB::table('cashier_shift_handover_receipts')
            ->where('receiving_cashier_shift_id', $cashierShiftId)
            ->exists();

        if ($hasReceipts) {
            return app(\Modules\Shift\Services\ShiftCashCountService::class)->confirmedOpeningHalalas($cashierShiftId);
        }

        return null;
    }

    /**
     * COR-05-01 — Project authoritative cashier shift fields (cash_collected and handover_notes).
     * Distinguishes authoritative null notes from an unsupported shift.
     *
     * @return array{cashCollectedHalalas: ?int, cashCollected: ?string, handoverNotes: ?string}|null
     */
    private function resolveCashierShiftFields(Operation $op, ?string $cashierShiftId = null): ?array
    {
        $cashierShiftId ??= $this->resolveCashierShiftId($op);
        if (! $cashierShiftId) {
            return null;
        }

        $row = DB::table('cashier_shifts')
            ->where('id', $cashierShiftId)
            ->select('cash_collected', 'handover_notes')
            ->first();

        if (! $row) {
            return null;
        }

        $cashCollectedHalalas = $row->cash_collected !== null
            ? ShiftFinancialCalculator::storedSarToHalalas($row->cash_collected)
            : null;

        $cashCollected = $row->cash_collected !== null
            ? number_format((float) $row->cash_collected, 2, '.', '')
            : null;

        return [
            'cashCollectedHalalas' => $cashCollectedHalalas,
            'cashCollected' => $cashCollected,
            'handoverNotes' => $row->handover_notes,
        ];
    }

    /**
     * COR-05-02 — Project the latest accountant correction command evidence for the current revision
     * so that clients can safely verify attribution on lost responses without repeating financial POSTs.
     *
     * @return array{revisionNumber: int, reason: string, actorType: string, actorId: string, createdAt: string}|null
     */
    private function resolveShiftLastCorrection(Operation $op, ?int $reportRevision): ?array
    {
        if (! $reportRevision) {
            return null;
        }

        $correction = DB::table('shift_report_corrections')
            ->where('company_id', $op->company_id)
            ->where('branch_id', $op->branch_id)
            ->where('new_revision_number', $reportRevision)
            ->orderByDesc('created_at')
            ->first();

        if (! $correction) {
            return null;
        }

        return [
            'revisionNumber' => (int) $correction->new_revision_number,
            'reason' => (string) $correction->reason,
            'actorType' => (string) $correction->actor_type,
            'actorId' => (string) $correction->actor_id,
            'createdAt' => (string) $correction->created_at,
        ];
    }
}
