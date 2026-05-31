<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Reminder;

/**
 * Accountant (المحاسب) dashboard + per-module review (BACKEND_API_SPEC.md §6.3).
 * Approve/reject actions are served by the shared /operations endpoints.
 */
class AccountantController extends AsabController
{
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
                'cashAmount' => 'sometimes|integer',
                'bankAmount' => 'sometimes|integer',
                'deliveryApps' => 'sometimes|array',
                'varianceReason' => 'sometimes|string|max:80',
                'varianceAllocations' => 'sometimes|array',
            ]);
            $payload = $op->payload ?? [];
            $payload['reconciliation'] = array_merge($payload['reconciliation'] ?? [], $data);
            $op->update(['payload' => $payload]);

            return $this->ok(['id' => $op->id, 'reconciliation' => $payload['reconciliation']]);
        });
    }

    public function convertToAsset(Request $request, string $invoiceId): JsonResponse
    {
        return $this->run(function () use ($request) {
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
