<?php

namespace Modules\Admin\Http\Controllers\Supplier;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\SupplierItem;

/**
 * Supplier (المورد; BACKEND_API_SPEC.md §6.6) portal — orders + catalog.
 */
class SupplierController extends AsabController
{
    public function overview(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $base = Operation::where('module_key', 'purchases');
            $userId = $request->user()->id;

            return $this->ok([
                'kpis' => [
                    'newOrders' => (clone $base)->where('status', 'pending')->count(),
                    'acceptedThisMonth' => (clone $base)->where('status', 'approved')->whereMonth('updated_at', now()->month)->count(),
                    'totalSalesThisMonth' => (int) (clone $base)->whereMonth('operation_date', now()->month)->sum('amount'),
                    'activeItems' => SupplierItem::where('supplier_user_id', $userId)->where('status', 'active')->count(),
                    'totalItems' => SupplierItem::where('supplier_user_id', $userId)->count(),
                ],
                'recentOrders' => (clone $base)->orderByDesc('operation_date')->limit(8)->get()
                    ->map(fn ($o) => ['id' => $o->id, 'publicId' => $o->public_id, 'total' => $o->amount, 'status' => $o->status])->all(),
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

            return $this->paginated($p, array_map(fn ($o) => [
                'id' => $o->id, 'publicId' => $o->public_id, 'total' => $o->amount, 'status' => $o->status,
            ], $p->items()));
        });
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['deliveryDate' => 'required|date', 'note' => 'nullable|string']);
            $op = $this->find($id);
            DB::transaction(function () use ($op, $data) {
                $payload = $op->payload ?? [];
                $payload['supplierResponse'] = ['accepted' => true, 'deliveryDate' => $data['deliveryDate'], 'note' => $data['note'] ?? null];
                $op->update(['status' => 'confirmed', 'payload' => $payload]);
            });

            return $this->ok($this->present($op->fresh()));
        });
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['reason' => 'required|string|max:500', 'note' => 'nullable|string']);
            $op = $this->find($id);
            DB::transaction(function () use ($op, $data) {
                $payload = $op->payload ?? [];
                $payload['supplierResponse'] = ['accepted' => false, 'reason' => $data['reason'], 'note' => $data['note'] ?? null];
                $op->update(['status' => 'rejected', 'reject_reason' => $data['reason'], 'payload' => $payload]);
            });

            return $this->ok($this->present($op->fresh()));
        });
    }

    public function markDelivered(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['deliveredAt' => 'nullable|date', 'deliveryNote' => 'nullable|string']);
            $op = $this->find($id);
            DB::transaction(function () use ($op, $data) {
                $payload = $op->payload ?? [];
                $payload['delivery'] = ['deliveredAt' => $data['deliveredAt'] ?? now()->toIso8601String(), 'note' => $data['deliveryNote'] ?? null];
                $op->update(['status' => 'delivered', 'payload' => $payload]);
            });

            return $this->ok($this->present($op->fresh()));
        });
    }

    public function items(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $items = SupplierItem::where('supplier_user_id', $request->user()->id)->orderBy('name')->get();

            return $this->listResponse($items->map([$this, 'presentItem'])->all());
        });
    }

    public function storeItem(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'code' => 'nullable|string|max:32',
                'name' => 'required|string|max:200',
                'unit' => 'nullable|string|max:16',
                'price' => 'required|integer|min:0',
                'minQty' => 'nullable|integer|min:0',
            ]);
            $item = SupplierItem::create([
                'supplier_user_id' => $request->user()->id,
                'code' => $data['code'] ?? null,
                'name' => $data['name'],
                'unit' => $data['unit'] ?? null,
                'price' => $data['price'],
                'min_qty' => $data['minQty'] ?? null,
                'status' => 'active',
            ]);

            return $this->created($this->presentItem($item));
        });
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $item = SupplierItem::where('supplier_user_id', $request->user()->id)->findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:200',
                'unit' => 'sometimes|string|max:16',
                'price' => 'sometimes|integer|min:0',
                'minQty' => 'sometimes|integer|min:0',
            ]);
            $item->update(array_filter([
                'name' => $data['name'] ?? null,
                'unit' => $data['unit'] ?? null,
                'price' => $data['price'] ?? null,
                'min_qty' => $data['minQty'] ?? null,
            ], fn ($v) => $v !== null));

            return $this->ok($this->presentItem($item->fresh()));
        });
    }

    public function toggleItem(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $item = SupplierItem::where('supplier_user_id', $request->user()->id)->findOrFail($id);
            $item->update(['status' => $item->status === 'active' ? 'inactive' : 'active']);

            return $this->ok($this->presentItem($item->fresh()));
        });
    }

    public function destroyItem(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            SupplierItem::where('supplier_user_id', $request->user()->id)->findOrFail($id)->delete();

            return $this->noContent();
        });
    }

    public function reports(Request $request): JsonResponse
    {
        return $this->run(function () {
            $base = Operation::where('module_key', 'purchases')->where('status', '!=', 'rejected');

            return $this->ok([
                'totalRevenue' => (int) (clone $base)->sum('amount'),
                'orderCount' => (clone $base)->count(),
                'averageOrderValue' => (int) round((clone $base)->avg('amount') ?? 0),
                'topItems' => [],
                'topBranches' => [],
                'monthly' => [],
            ]);
        });
    }

    private function find(string $id): Operation
    {
        return Operation::where('module_key', 'purchases')->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))->firstOrFail();
    }

    private function present(Operation $o): array
    {
        return ['id' => $o->id, 'publicId' => $o->public_id, 'total' => $o->amount, 'status' => $o->status];
    }

    public function presentItem(SupplierItem $i): array
    {
        return [
            'id' => $i->id,
            'code' => $i->code,
            'name' => $i->name,
            'unit' => $i->unit,
            'price' => $i->price,
            'minQty' => $i->min_qty,
            'status' => $i->status,
        ];
    }
}
