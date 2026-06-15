<?php

namespace Modules\Admin\Http\Controllers\Supplier;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\SupplierItem;
use Modules\Admin\Services\ExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Supplier (المورد; BACKEND_API_SPEC.md §6.6) portal — orders + catalog.
 */
class SupplierController extends AsabController
{
    public function __construct(private readonly ExportService $exports) {}

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
            $data = $request->validate(['deliveryDate' => 'nullable|date', 'note' => 'nullable|string']);
            $op = $this->find($id);
            DB::transaction(function () use ($op, $data) {
                $payload = $op->payload ?? [];
                $payload['supplierResponse'] = ['accepted' => true, 'deliveryDate' => $data['deliveryDate'] ?? null, 'note' => $data['note'] ?? null];
                $op->update(['status' => 'accepted', 'payload' => $payload]);
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
                // priceHalalas is the doc field name; price is the legacy alias. Money is integer halalas.
                'price' => 'required_without:priceHalalas|integer|min:0',
                'priceHalalas' => 'required_without:price|integer|min:0',
                'minQty' => 'nullable|integer|min:0',
                'maxQty' => 'nullable|integer|min:0',
                'available' => 'nullable|boolean',
                'leadTimeDays' => 'nullable|integer|min:0',
            ]);
            $available = $data['available'] ?? true;
            $item = SupplierItem::create([
                'supplier_user_id' => $request->user()->id,
                'code' => $data['code'] ?? null,
                'name' => $data['name'],
                'unit' => $data['unit'] ?? null,
                'price' => $data['priceHalalas'] ?? $data['price'],
                'min_qty' => $data['minQty'] ?? null,
                'max_qty' => $data['maxQty'] ?? null,
                'available' => $available,
                'lead_time_days' => $data['leadTimeDays'] ?? null,
                'status' => $available ? 'active' : 'inactive',
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
                // priceHalalas is the doc field name; price is the legacy alias. Money is integer halalas.
                'price' => 'sometimes|integer|min:0',
                'priceHalalas' => 'sometimes|integer|min:0',
                'minQty' => 'sometimes|integer|min:0',
                'maxQty' => 'sometimes|integer|min:0',
                'available' => 'sometimes|boolean',
                'leadTimeDays' => 'sometimes|integer|min:0',
            ]);
            $updates = array_filter([
                'name' => $data['name'] ?? null,
                'unit' => $data['unit'] ?? null,
                'price' => $data['priceHalalas'] ?? $data['price'] ?? null,
                'min_qty' => $data['minQty'] ?? null,
                'max_qty' => $data['maxQty'] ?? null,
                'lead_time_days' => $data['leadTimeDays'] ?? null,
            ], fn ($v) => $v !== null);
            // available is a boolean: handle separately so an explicit false is not dropped by array_filter.
            if ($request->has('available')) {
                $updates['available'] = $data['available'];
                // Keep the legacy status flag in sync with availability.
                $updates['status'] = $data['available'] ? 'active' : 'inactive';
            }
            $item->update($updates);

            return $this->ok($this->presentItem($item->fresh()));
        });
    }

    public function toggleItem(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $item = SupplierItem::where('supplier_user_id', $request->user()->id)->findOrFail($id);
            $nowActive = $item->status !== 'active';
            // Keep the availability flag in sync with the toggled status.
            $item->update(['status' => $nowActive ? 'active' : 'inactive', 'available' => $nowActive]);

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

    /** GET /asab/supplier/items/export?format=xlsx|csv */
    public function itemsExport(Request $request): BinaryFileResponse
    {
        $format = $request->query('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';

        return $this->exports->supplierItems($format, $request->user()->id);
    }

    /** GET /asab/supplier/orders/export?status=accepted|rejected&format=xlsx|csv */
    public function ordersExport(Request $request): BinaryFileResponse
    {
        $format = $request->query('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';

        return $this->exports->supplierOrders($format, $request->query('status'));
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
            'priceHalalas' => $i->price,
            'minQty' => $i->min_qty,
            'maxQty' => $i->max_qty,
            'available' => $i->available,
            'leadTimeDays' => $i->lead_time_days,
            'status' => $i->status,
        ];
    }
}
