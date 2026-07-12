<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AuditLog;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Reminder;
use Modules\Admin\Services\AccountantDashboardService;
use Modules\Admin\Services\AssetDraftService;
use Modules\Admin\Services\ExpenseKpiService;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Services\PurchasePresenterService;
use Modules\Admin\Services\PurchaseReceivingBridge;
use Modules\Admin\Services\SalesReconciliationService;
use Modules\Admin\Support\AssetEnums;
use Modules\Admin\Support\TenantContext;

/**
 * Accountant (المحاسب) dashboard + per-module review (BACKEND_API_SPEC.md §6.3).
 * Approve/reject actions are served by the shared /operations endpoints.
 */
class AccountantController extends AsabController
{
    public function __construct(
        private readonly SalesReconciliationService $reconciler,
        private readonly AccountantDashboardService $dashboards,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /accountant/dashboard/activity-heatmap (MISSING_Dashboard §11.2). */
    public function activityHeatmap(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $from = $request->query('dateFrom', now()->subDays(30)->toDateString());
            $to = $request->query('dateTo', now()->toDateString()).' 23:59:59';

            $logs = AuditLog::where('actor_user_id', $request->user()->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->limit(50000)->get(['occurred_at']);

            $hours = array_fill(0, 24, 0);
            $days = array_fill(0, 7, 0);
            foreach ($logs as $log) {
                if (! $log->occurred_at) {
                    continue;
                }
                $at = Carbon::parse($log->occurred_at);
                $hours[(int) $at->hour]++;
                $days[(int) $at->dayOfWeek]++;
            }

            $dayLabels = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

            return $this->ok([
                'hours' => array_map(fn ($h, $c) => ['hour' => $h, 'count' => $c], array_keys($hours), $hours),
                'byDay' => array_map(fn ($d, $c) => ['day' => $d, 'dayAr' => $dayLabels[$d], 'totalCount' => $c], array_keys($days), $days),
            ]);
        });
    }

    /** GET /accountant/dashboard — ACC-0 «ملخص اليوم» (internal dashboard surface). */
    public function dashboard(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $branchIds = $this->assignedBranchIds();
            $actor = $request->user();

            return $this->ok([
                'kpis' => $this->dashboards->kpis($actor, $branchIds),
                'modules' => $this->dashboards->moduleGrid($actor, $branchIds),
                'progressToday' => $this->dashboards->progressToday($actor, $branchIds),
                'scope' => $this->dashboards->scope($actor, $branchIds, $this->tenant),
                'recentOperations' => $this->scopeToAssignedBranches(Operation::query())
                    ->orderByDesc('created_at')->limit(8)->get()->map(fn ($o) => $this->present($o))->all(),
            ]);
        });
    }

    public function operations(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = $this->scopeToAssignedBranches(Operation::query());
            if ($module = $request->query('moduleKey')) {
                $q->where('module_key', $module);
            }
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            // ACC-0.4 day pills (اليوم / أمس / هذا الأسبوع / هذا الشهر).
            if ($from = $request->query('dateFrom')) {
                $q->whereDate('operation_date', '>=', $from);
            }
            if ($to = $request->query('dateTo')) {
                $q->whereDate('operation_date', '<=', $to);
            }
            if ($search = $request->query('search')) {
                $q->where('public_id', 'like', "%{$search}%");
            }
            $p = $q->orderByDesc('operation_date')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map(fn ($o) => $this->present($o), $p->items()), [
                'summary' => [
                    'totalUploaded' => $p->total(),
                    'underReview' => (clone $q)->where('status', 'pending')->count(),
                    'approved' => (clone $q)->where('status', 'approved')->count(),
                    'rejected' => (clone $q)->where('status', 'rejected')->count(),
                ],
            ]);
        });
    }

    /** GET /accountant/expenses/kpis — ACC-2.1 cards + the invoice-match split. */
    public function expenseKpis(Request $request, ExpenseKpiService $kpis): JsonResponse
    {
        return $this->run(function () use ($request, $kpis) {
            $request->validate(['dateFrom' => 'sometimes|date', 'dateTo' => 'sometimes|date|after_or_equal:dateFrom']);

            return $this->ok($kpis->forRange(
                $request->user()->company_id,
                $this->assignedBranchIds(),
                $request->query('dateFrom'),
                $request->query('dateTo'),
            ));
        });
    }

    public function reminders(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = Reminder::query();
            if ($status = $request->query('status')) {
                $q->where('reminder_status', $status);
            }
            $items = $q->orderByDesc('created_at')->get();

            return $this->listResponse(
                $items->map(fn ($r) => [
                    'id' => $r->id,
                    'publicId' => $r->public_id,
                    'branchId' => $r->branch_id,
                    'reportType' => $r->report_type,
                    'moduleKey' => $r->module_key,
                    'urgency' => $r->urgency,
                    'reminderStatus' => $r->reminder_status,
                    'daysMissing' => $r->days_missing,
                    'requiredBy' => optional($r->required_by)->toIso8601String(),
                ])->all(),
                ['summary' => [
                    'notSent' => $items->where('reminder_status', 'not_sent')->count(),
                    'sent' => $items->where('reminder_status', 'sent')->count(),
                    'responded' => $items->where('reminder_status', 'responded')->count(),
                    'totalMissing' => $items->count(),
                ]],
            );
        });
    }

    /**
     * PATCH …/sales-details — the accountant's reconciliation edit (ACC-1.4).
     *
     * Accepts the canonical `channels[]` (SRS §4.3 enum) or the legacy
     * cash/bank/deliveryApps triple. The branch's total («مقفل») is never an
     * input; the match badge is re-derived from the resulting variance.
     */
    public function reconciliation(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $op = $this->findAssigned($id);
            if ($op->status === Operation::STATUS_FINAL) {
                return $this->fail('OP_ALREADY_FINAL', 'Operation is final-approved', 'العملية معتمدة نهائياً', [], 409);
            }
            $data = $request->validate([
                // canonical: one row per collection channel
                'channels' => 'sometimes|array|min:1',
                'channels.*.key' => 'required_with:channels|string|max:40',
                'channels.*.actualAmountHalalas' => 'required_with:channels|integer',
                'channels.*.posAmountHalalas' => 'sometimes|nullable|integer',
                // legacy fields
                'cashAmount' => 'sometimes|integer',
                'bankAmount' => 'sometimes|integer',
                'cashHalalas' => 'sometimes|integer',
                'bankHalalas' => 'sometimes|integer',
                'deliveryApps' => 'sometimes|array',
                'deliveryApps.*.name' => 'sometimes|string|max:80',
                'deliveryApps.*.amountHalalas' => 'sometimes|integer',
                'varianceReason' => 'sometimes|string|max:80',
            ]);

            $reconciliation = $this->reconciler->save($op, $data);

            return $this->ok([
                'id' => $op->id,
                'reconciliation' => $reconciliation,
                'totalCollectionHalalas' => $reconciliation['totals']['totalCollectionHalalas'],
                'varianceHalalas' => $reconciliation['totals']['varianceHalalas'],
                'match' => $op->fresh()->match,
            ]);
        });
    }

    /**
     * PATCH /operations/{id}/sales-lines/{rowId} — update one sales line inside
     * the operation's sales-lines payload (Accountant sales review).
     */
    public function salesLineUpdate(Request $request, string $id, string $rowId): JsonResponse
    {
        return $this->run(function () use ($request, $id, $rowId) {
            $op = $this->findAssigned($id);
            if ($op->status === Operation::STATUS_FINAL) {
                return $this->fail('OP_ALREADY_FINAL', 'Operation is final-approved', 'العملية معتمدة نهائياً', [], 409);
            }
            $data = $request->validate([
                'amountBeforeTaxHalalas' => 'sometimes|integer',
                'vatHalalas' => 'sometimes|integer',
                'amountAfterTaxHalalas' => 'sometimes|integer',
            ]);

            $updated = DB::transaction(function () use ($op, $rowId, $data) {
                $payload = $op->payload ?? [];
                // Tolerant of both 'salesLines' (doc) and 'sales_lines' (legacy) keys.
                $key = isset($payload['salesLines']) ? 'salesLines' : (isset($payload['sales_lines']) ? 'sales_lines' : 'salesLines');
                $lines = $payload[$key] ?? [];

                $found = null;
                foreach ($lines as $idx => $line) {
                    $lineId = $line['id'] ?? $line['rowId'] ?? (string) $idx;
                    if ((string) $lineId === (string) $rowId) {
                        $lines[$idx] = array_merge($line, array_filter([
                            'amountBeforeTaxHalalas' => $data['amountBeforeTaxHalalas'] ?? null,
                            'vatHalalas' => $data['vatHalalas'] ?? null,
                            'amountAfterTaxHalalas' => $data['amountAfterTaxHalalas'] ?? null,
                        ], fn ($v) => $v !== null));
                        $found = $lines[$idx];
                        break;
                    }
                }

                if ($found === null) {
                    throw new \Modules\Admin\Exceptions\AsabException('NOT_FOUND', 'Sales line not found', 'سطر المبيعات غير موجود', 404);
                }

                $payload[$key] = $lines;
                $op->update(['payload' => $payload]);

                return $found;
            });

            return $this->ok(['operationId' => $op->id, 'rowId' => $rowId, 'row' => $updated]);
        });
    }

    /**
     * PATCH /operations/{id}/purchase-lines/{rowId} — accountant edit of one
     * purchase line (ACC-3.4). Recomputes the line total, operation `amount` and
     * the 3-way `match` (a diverging unit price flips it to `diff` even when the
     * quantity agrees), and records old→new in an audit step.
     */
    public function purchaseLineUpdate(Request $request, PurchasePresenterService $purchases, PurchaseReceivingBridge $receiving, OperationService $operations, string $id, string $rowId): JsonResponse
    {
        return $this->run(function () use ($request, $purchases, $receiving, $operations, $id, $rowId) {
            $op = $this->findAssigned($id, 'purchases');
            $operations->assertMutable($op, 'لا يمكن تعديل عملية شراء مُغلقة');

            $data = $request->validate([
                'ordQty' => 'sometimes|numeric|min:0',
                'rcvQty' => 'sometimes|nullable|numeric|min:0',
                'unitPriceHalalas' => 'sometimes|integer|min:0',
            ]);

            $result = DB::transaction(function () use ($op, $purchases, $receiving, $operations, $rowId, $data, $request) {
                $received = $receiving->receivedByRow($op);
                $lines = $purchases->lines($op, $received);

                $target = collect($lines)->search(fn ($l) => $l['rowId'] === (string) $rowId);
                if ($target === false) {
                    throw new \Modules\Admin\Exceptions\AsabException('NOT_FOUND', 'Purchase line not found', 'سطر الشراء غير موجود', 404);
                }
                $before = $lines[$target];

                // Persist raw canonical fields; the presenter re-derives the match.
                // An explicit rcvQty edit overrides the legacy bridge for this line.
                $raw = array_map(fn ($l) => $this->rawPurchaseLine($l), $lines);
                $raw[$target] = array_merge($raw[$target], array_filter([
                    'ordQty' => $data['ordQty'] ?? null,
                    'unitPriceHalalas' => $data['unitPriceHalalas'] ?? null,
                ], fn ($v) => $v !== null));
                if (array_key_exists('rcvQty', $data)) {
                    $raw[$target]['rcvQty'] = $data['rcvQty'];
                }

                $payload = $op->payload ?? [];
                $payload['purchaseItems'] = $raw;
                $op->update(['payload' => $payload]);

                $derived = $purchases->lines($op->fresh(), $received);
                $recompute = $purchases->recomputeMatch($derived);
                $op->update([
                    'payload' => array_merge($payload, ['purchaseItems' => $recompute['purchaseItems']]),
                    'amount' => $recompute['amount'],
                    'match' => $recompute['match'],
                    'diff_note' => $recompute['diffNote'],
                ]);

                $after = collect($derived)->firstWhere('rowId', (string) $rowId);
                $operations->recordStep(
                    $op, 'review', 'عدّل المحاسب سطر الشراء: '.($before['item'] ?? $rowId), $request->user(),
                    null, ['rowId' => (string) $rowId, 'before' => $this->purchaseLineSnapshot($before), 'after' => $this->purchaseLineSnapshot($after)],
                );

                return ['op' => $op->fresh(), 'line' => $after, 'match' => $recompute['match']];
            });

            return $this->ok([
                'operationId' => $op->id,
                'rowId' => $rowId,
                'row' => $result['line'],
                'match' => $result['match'],
                'amount' => $result['op']->amount,
            ]);
        });
    }

    /** A derived line stripped back to its stored (raw) fields. */
    private function rawPurchaseLine(array $line): array
    {
        return [
            'rowId' => $line['rowId'], 'item' => $line['item'], 'itemId' => $line['itemId'], 'unit' => $line['unit'],
            'ordQty' => $line['ordQty'], 'rcvQty' => $line['rcvQty'],
            'unitPriceHalalas' => $line['unitPriceHalalas'], 'orderedUnitPriceHalalas' => $line['orderedUnitPriceHalalas'],
        ];
    }

    /** @return array<string, mixed> compact before/after for the audit step */
    private function purchaseLineSnapshot(?array $line): array
    {
        return $line === null ? [] : [
            'ordQty' => $line['ordQty'], 'rcvQty' => $line['rcvQty'],
            'unitPriceHalalas' => $line['unitPriceHalalas'], 'totalHalalas' => $line['totalHalalas'] ?? null,
        ];
    }

    /**
     * POST /operations/{id}/notes — append an accountant note to the operation.
     */
    public function addNote(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $op = $this->findAssigned($id);
            $data = $request->validate(['note' => 'required|string']);

            $noteId = (string) Str::uuid();
            $createdAt = now()->toIso8601String();

            $note = DB::transaction(function () use ($op, $request, $data, $noteId, $createdAt) {
                $payload = $op->payload ?? [];
                $notes = $payload['accountantNotes'] ?? [];
                $entry = [
                    'id' => $noteId,
                    'note' => $data['note'],
                    'authorId' => $request->user()->id,
                    'createdAt' => $createdAt,
                ];
                $notes[] = $entry;
                $payload['accountantNotes'] = $notes;
                $op->update(['payload' => $payload]);

                return $entry;
            });

            return $this->created([
                'id' => $note['id'],
                'note' => $note['note'],
                'createdAt' => $note['createdAt'],
            ]);
        });
    }

    /**
     * POST …/expense-invoices/{invoiceId}/convert-to-asset-draft — ACC-2.4.
     *
     * `{invoiceId}` addresses the expenses **operation** (EXP-xxxx); the invoice
     * inside it is picked by `invoiceIndex`. The draft's value is the invoice's
     * pre-tax amount, and the invoice is stamped «محوّل» so it converts once.
     */
    public function convertToAsset(Request $request, AssetDraftService $drafts, string $invoiceId): JsonResponse
    {
        return $this->run(function () use ($request, $drafts, $invoiceId) {
            $data = $request->validate([
                'invoiceIndex' => 'sometimes|integer|min:0',
                'assetName' => 'required|string|max:200',
                'category' => 'required|string|max:32',
                'usefulLifeMonths' => ['required', 'integer', AssetEnums::usefulLifeRule()],
                'targetBranches' => 'required|array|min:1',
                'targetBranches.*' => 'required|string',
                'custodian' => 'required|string|max:200',
                'qty' => 'required|integer|min:1',
                'notes' => 'nullable|string',
                'amount' => 'sometimes|integer|min:0',
                'vendor' => 'sometimes|string|max:200',
                'invNum' => 'sometimes|string|max:64',
            ]);
            // The wizard's branch checkboxes are caller data: an out-of-scope
            // branch must not receive assets minted from someone else's invoice.
            foreach ($data['targetBranches'] as $branchId) {
                $this->assertBranchAssigned($branchId);
            }

            $op = $this->findAssigned($invoiceId, 'expenses');

            return $this->created(
                $drafts->convert($op, (int) ($data['invoiceIndex'] ?? 0), $data, $request->user()),
            );
        });
    }

    private function findAssigned(string $id, ?string $moduleKey = null): Operation
    {
        return $this->scopeToAssignedBranches(
            Operation::where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))
                ->when($moduleKey !== null, fn ($q) => $q->where('module_key', $moduleKey)),
        )->firstOrFail();
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
            'origin' => $op->origin,
            'operationDate' => optional($op->operation_date)->toIso8601String(),
        ];
    }
}
