<?php

namespace Modules\Admin\Http\Controllers\Procurement;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationService;

/**
 * Procurement Manager (مدير المشتريات; BACKEND_API_SPEC.md §6.5).
 * Purchase operations flow through the shared pipeline; consolidation/sending
 * are tracked on the operation payload + status.
 */
class ProcurementController extends AsabController
{
    public function __construct(private readonly OperationService $service) {}

    public function overview(): JsonResponse
    {
        return $this->run(function () {
            $base = Operation::where('module_key', 'purchases');

            return $this->ok([
                'kpis' => [
                    'newOrders' => (clone $base)->where('status', 'pending')->count(),
                    'consolidated' => (clone $base)->where('status', 'approved')->count(),
                    'sentToSuppliers' => (clone $base)->where('status', 'final-approved')->count(),
                    'ordersValueThisWeek' => (int) (clone $base)->where('operation_date', '>=', now()->subWeek())->sum('amount'),
                ],
                'newOrders' => (clone $base)->where('status', 'pending')->orderByDesc('operation_date')->limit(10)->get()
                    ->map(fn ($o) => ['id' => $o->id, 'publicId' => $o->public_id, 'branchId' => $o->branch_id, 'total' => $o->amount, 'urgency' => $o->match === 'diff' ? 'عاجل' : 'عادي'])->all(),
            ]);
        });
    }

    public function orders(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = Operation::where('module_key', 'purchases');
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            $p = $q->orderByDesc('operation_date')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map([$this, 'present'], $p->items()));
        });
    }

    public function show(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $op = Operation::where('module_key', 'purchases')->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))->firstOrFail();
            $data = $this->present($op);
            $data['payload'] = $op->payload;

            return $this->ok($data);
        });
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->present(
            $this->service->approve($this->find($id), $request->user(), $request->input('note')),
        )));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['reason' => 'required|string|max:500', 'note' => 'nullable|string']);

            return $this->ok($this->present($this->service->reject($this->find($id), $request->user(), $data['reason'], $data['note'] ?? null)));
        });
    }

    public function partialReject(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate([
                'reason' => 'required|string|max:500',
                // doc field `rejectedItemIds`; `itemIds` accepted as an alias (non-breaking).
                'rejectedItemIds' => 'sometimes|array',
                'itemIds' => 'sometimes|array',
                'note' => 'nullable|string',
            ]);
            $rejectedItemIds = $data['rejectedItemIds'] ?? $data['itemIds'] ?? null;
            if (empty($rejectedItemIds)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'rejectedItemIds' => ['The rejectedItemIds field is required.'],
                ]);
            }
            $op = $this->find($id);
            DB::transaction(function () use ($op, $rejectedItemIds, $data) {
                $payload = $op->payload ?? [];
                $payload['partialReject'] = ['reason' => $data['reason'], 'rejectedItemIds' => array_values($rejectedItemIds), 'note' => $data['note'] ?? null];
                $op->update(['status' => 'partial_reject', 'payload' => $payload]);
            });

            return $this->ok($this->present($op->fresh()));
        });
    }

    /**
     * Bulk-approve purchase orders (COMPANY_DASHBOARD_API_SPEC.md §5.3d).
     * Either an explicit `orderIds` list, or all pending orders filtered by
     * `branch` and/or `supplier`. Each transition runs through the shared
     * approval pipeline; the whole batch is atomic.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'orderIds' => 'sometimes|array',
                'orderIds.*' => 'string',
                'branch' => 'sometimes|nullable|string',
                'supplier' => 'sometimes|nullable|string',
            ]);

            $q = Operation::where('module_key', 'purchases')->where('status', Operation::STATUS_PENDING);
            if (! empty($data['orderIds'])) {
                $ids = $data['orderIds'];
                $q->where(fn ($w) => $w->whereIn('id', $ids)->orWhereIn('public_id', $ids));
            }
            if (! empty($data['branch'])) {
                $q->where('branch_id', $data['branch']);
            }
            if (! empty($data['supplier'])) {
                $q->where('payload->supplierId', $data['supplier']);
            }

            $approved = [];
            DB::transaction(function () use ($q, $request, &$approved) {
                foreach ($q->get() as $op) {
                    $this->service->approve($op, $request->user());
                    $approved[] = $op->id;
                }
            });

            return $this->ok(['approved' => $approved, 'count' => count($approved)]);
        });
    }

    public function consolidate(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['orderIds' => 'required|array', 'supplierId' => 'required|string']);
            $groupId = (string) \Illuminate\Support\Str::uuid();
            DB::transaction(function () use ($data, $groupId) {
                Operation::where(fn ($q) => $q->whereIn('id', $data['orderIds'])->orWhereIn('public_id', $data['orderIds']))
                    ->get()->each(function ($op) use ($groupId, $data) {
                        $payload = $op->payload ?? [];
                        $payload['consolidatedGroupId'] = $groupId;
                        $payload['supplierId'] = $data['supplierId'];
                        $op->update(['status' => 'approved', 'payload' => $payload]);
                    });
            });

            return $this->created(['consolidatedGroupId' => $groupId, 'orderCount' => count($data['orderIds'])]);
        });
    }

    public function send(Request $request, string $groupId): JsonResponse
    {
        return $this->run(function () use ($groupId) {
            $affected = Operation::where('payload->consolidatedGroupId', $groupId)->get();
            $sentAt = now()->toIso8601String();
            DB::transaction(function () use ($affected, $sentAt) {
                foreach ($affected as $op) {
                    // Stamp sentAt on the payload so the company "sent" listing surfaces it.
                    $payload = $op->payload ?? [];
                    $payload['sentAt'] = $sentAt;
                    $op->update(['status' => 'final-approved', 'payload' => $payload]);
                }
            });

            return $this->ok(['groupId' => $groupId, 'sent' => $affected->count()]);
        });
    }

    public function suppliers(): JsonResponse
    {
        try {
            $items = \Modules\Supplier\Models\Supplier::orderBy('name')->limit(200)->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name ?? null, 'category' => $s->category ?? null])->all();
        } catch (\Throwable $e) {
            $items = [];
        }

        return $this->listResponse($items);
    }

    public function items(): JsonResponse
    {
        try {
            $items = \Modules\Purchase\Models\Item::limit(500)->get()->map(fn ($i) => ['id' => $i->id, 'name' => $i->name ?? null])->all();
        } catch (\Throwable $e) {
            $items = [];
        }

        return $this->listResponse($items);
    }

    private function find(string $id): Operation
    {
        return Operation::where('module_key', 'purchases')->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))->firstOrFail();
    }

    private function present(Operation $o): array
    {
        return [
            'id' => $o->id,
            'publicId' => $o->public_id,
            'branchId' => $o->branch_id,
            'total' => $o->amount,
            'status' => $o->status,
            'diffNote' => $o->diff_note,
            'urgency' => $o->match === 'diff' ? 'عاجل' : 'عادي',
            'operationDate' => optional($o->operation_date)->toIso8601String(),
        ];
    }
}
