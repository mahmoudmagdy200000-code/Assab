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

/**
 * Accountant (المحاسب) dashboard + per-module review (BACKEND_API_SPEC.md §6.3).
 * Approve/reject actions are served by the shared /operations endpoints.
 */
class AccountantController extends AsabController
{
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

    public function dashboard(): JsonResponse
    {
        return $this->run(function () {
            $byStatus = Operation::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
            $pending = (int) ($byStatus['pending'] ?? 0);
            $approved = (int) ($byStatus['approved'] ?? 0);
            $final = (int) ($byStatus['final-approved'] ?? 0);
            $total = max(1, array_sum($byStatus->all()));

            return $this->ok([
                'kpis' => [
                    'awaitingReview' => $pending,
                    'iApproved' => $approved,
                    'finalApproved' => $final,
                    'approvalRate' => (int) round((($approved + $final) / $total) * 100),
                    'overdueCount' => Operation::where('status', 'pending')->where('submitted_at', '<', now()->subDays(2))->count(),
                ],
                'modules' => $this->moduleCounts(),
                'recentOperations' => Operation::orderByDesc('created_at')->limit(8)->get()->map(fn ($o) => $this->present($o))->all(),
            ]);
        });
    }

    public function operations(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = Operation::query();
            if ($module = $request->query('moduleKey')) {
                $q->where('module_key', $module);
            }
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
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

    public function reconciliation(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $op = Operation::where('id', $id)->orWhere('public_id', $id)->firstOrFail();
            if ($op->status === Operation::STATUS_FINAL) {
                return $this->fail('OP_ALREADY_FINAL', 'Operation is final-approved', 'العملية معتمدة نهائياً', [], 409);
            }
            $data = $request->validate([
                // canonical fields
                'cashAmount' => 'sometimes|integer',
                'bankAmount' => 'sometimes|integer',
                // doc aliases (halalas)
                'cashHalalas' => 'sometimes|integer',
                'bankHalalas' => 'sometimes|integer',
                'deliveryApps' => 'sometimes|array',
                'deliveryApps.*.name' => 'sometimes|string|max:80',
                'deliveryApps.*.amountHalalas' => 'sometimes|integer',
                'varianceReason' => 'sometimes|string|max:80',
                'varianceAllocations' => 'sometimes|array',
            ]);

            // Read doc aliases, falling back to the existing field names (non-breaking).
            $cash = (int) ($data['cashHalalas'] ?? $data['cashAmount'] ?? 0);
            $bank = (int) ($data['bankHalalas'] ?? $data['bankAmount'] ?? 0);
            $deliveryApps = $data['deliveryApps'] ?? [];
            $deliveryTotal = array_sum(array_map(fn ($a) => (int) ($a['amountHalalas'] ?? 0), $deliveryApps));
            $totalCollection = $cash + $bank + $deliveryTotal;

            // Expected/total sales for this operation: the operation amount, with payload fallbacks.
            $expected = (int) (
                $op->amount
                ?? ($op->payload['expectedHalalas']
                    ?? ($op->payload['totalSalesHalalas']
                        ?? ($op->payload['totalHalalas'] ?? 0)))
            );
            $variance = $totalCollection - $expected;

            $payload = $op->payload ?? [];
            $payload['reconciliation'] = array_merge($payload['reconciliation'] ?? [], $data, [
                'totalCollectionHalalas' => $totalCollection,
                'varianceHalalas' => $variance,
            ]);
            $op->update(['payload' => $payload]);

            return $this->ok([
                'id' => $op->id,
                'reconciliation' => $payload['reconciliation'],
                'totalCollectionHalalas' => $totalCollection,
                'varianceHalalas' => $variance,
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
            $op = Operation::where('id', $id)->orWhere('public_id', $id)->firstOrFail();
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
     * POST /operations/{id}/notes — append an accountant note to the operation.
     */
    public function addNote(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $op = Operation::where('id', $id)->orWhere('public_id', $id)->firstOrFail();
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

    public function convertToAsset(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt, string $invoiceId): JsonResponse
    {
        return $this->run(function () use ($request, $rt) {
            $data = $request->validate([
                'assetName' => 'required|string|max:200',
                'category' => 'required|string|max:32',
                'usefulLifeMonths' => 'required|integer|min:1',
                'targetBranches' => 'required|array',
                'custodian' => 'required|string|max:200',
                'qty' => 'required|integer|min:1',
                'notes' => 'nullable|string',
                'amount' => 'sometimes|integer|min:0',
                'vendor' => 'sometimes|string|max:200',
                'invNum' => 'sometimes|string|max:64',
            ]);

            $draft = \Modules\Admin\Models\AssetDraft::create([
                'draft_id' => 'DRAFT-'.strtoupper(\Illuminate\Support\Str::random(8)),
                'company_id' => $request->user()->company_id,
                'inv_num' => $data['invNum'] ?? null,
                'vendor' => $data['vendor'] ?? null,
                'amount' => $data['amount'] ?? 0,
                'asset_name' => $data['assetName'],
                'category' => $data['category'],
                'useful_life_months' => $data['usefulLifeMonths'],
                'target_branches' => $data['targetBranches'],
                'custodian' => $data['custodian'],
                'qty' => $data['qty'],
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by_id' => $request->user()->id,
            ]);
            foreach (($data['targetBranches'] ?: [null]) as $branchId) {
                $rt->assetDraftCreated($draft, $branchId);
            }

            return $this->created(['draftId' => $draft->draft_id, 'status' => 'draft']);
        });
    }

    private function moduleCounts(): array
    {
        $modules = ['sales' => 'المبيعات', 'expenses' => 'المصروفات', 'purchases' => 'المشتريات', 'inventory' => 'المخزون', 'waste' => 'الهدر', 'cash' => 'النقدية'];
        $out = [];
        foreach ($modules as $key => $label) {
            $base = Operation::where('module_key', $key);
            $out[] = [
                'key' => $key,
                'label' => $label,
                'pendingCount' => (clone $base)->where('status', 'pending')->count(),
                'totalCount' => $base->count(),
            ];
        }

        return $out;
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
