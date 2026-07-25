<?php

namespace Modules\Admin\Http\Controllers\Procurement;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Services\TenantBranchResolver;
use Modules\Admin\Support\TenantContext;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Services\OrderConsolidationService;

/**
 * Procurement Manager (مدير المشتريات; BACKEND_API_SPEC.md §6.5).
 * Purchase operations flow through the shared pipeline; consolidation/sending
 * are tracked on the operation payload + status.
 */
class ProcurementController extends AsabController
{
    public function __construct(
        private readonly OperationService $service,
        private readonly OrderConsolidationService $consolidation,
        private readonly TenantBranchResolver $branches,
        private readonly TenantContext $tenant,
    ) {}

    public function overview(): JsonResponse
    {
        return $this->run(function () {
            $base = Operation::where('module_key', 'purchases');
            // PRC-1.1 — headline figures over the REAL (bridge) pipeline + monthly savings.
            $bridge = $this->consolidation->procurementKpis($this->branches->legacyBranchIds($this->tenant));

            return $this->ok([
                'kpis' => [
                    'newOrders' => (clone $base)->where('status', 'pending')->count(),
                    'consolidated' => (clone $base)->where('status', 'approved')->count(),
                    'sentToSuppliers' => (clone $base)->where('status', 'final-approved')->count(),
                    'ordersValueThisWeek' => (int) (clone $base)->where('operation_date', '>=', now()->subWeek())->sum('amount'),
                    'incoming' => $bridge['incoming'],
                    'readyToSend' => $bridge['readyToSend'],
                    'sentAwaitingConfirmation' => $bridge['sentAwaitingConfirmation'],
                ],
                'monthlySavings' => $bridge['monthlySavings'],
                'newOrders' => (clone $base)->where('status', 'pending')->orderByDesc('operation_date')->limit(10)->get()
                    ->map(fn ($o) => [
                        'id' => $o->id, 'publicId' => $o->public_id, 'branchId' => $o->branch_id, 'total' => $o->amount,
                    ] + $this->urgencyOf($o))->all(),
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
            $rejectedItemIds = array_values($data['rejectedItemIds'] ?? $data['itemIds'] ?? []);
            if (empty($rejectedItemIds)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'rejectedItemIds' => ['The rejectedItemIds field is required.'],
                ]);
            }

            $op = $this->find($id);
            $this->service->assertMutable($op, 'لا يمكن رفض عملية مُغلقة جزئياً');

            // PRC-2.6 — rejected ids must belong to the order's own line items.
            $validIds = collect($op->payload['items'] ?? [])
                ->map(fn ($it) => is_array($it) ? ($it['itemId'] ?? $it['id'] ?? null) : $it)->filter()->all();
            $unknown = array_values(array_diff($rejectedItemIds, $validIds));
            if (! empty($unknown)) {
                return $this->fail('INVALID_ITEM_IDS', 'Some rejected item ids are not part of this order',
                    'بعض الأصناف المرفوضة ليست ضمن هذا الطلب', ['unknown' => $unknown], 422);
            }

            DB::transaction(function () use ($op, $rejectedItemIds, $data, $request) {
                $payload = $op->payload ?? [];
                $payload['partialReject'] = [
                    'reason' => $data['reason'], 'rejectedItemIds' => $rejectedItemIds, 'note' => $data['note'] ?? null,
                ];
                $op->update(['status' => 'partial_reject', 'payload' => $payload]);
                // Audit step so the operation history reflects the partial rejection.
                $this->service->recordStep(
                    $op, 'rejected', 'رفض جزئي — السبب: '.$data['reason'], $request->user(), $data['note'] ?? null,
                    ['partialReject' => true, 'rejectedItemIds' => $rejectedItemIds],
                );
            });

            return $this->ok($this->present($op->fresh()) + [
                'statusLabel' => 'مرفوض جزئياً',
                'partialReject' => $op->fresh()->payload['partialReject'] ?? null,
            ]);
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
            $data = $request->validate([
                'orderIds' => 'required|array|min:1', 'orderIds.*' => 'string', 'supplierId' => 'required|string',
            ]);

            $ops = Operation::where('module_key', 'purchases')
                ->where(fn ($q) => $q->whereIn('id', $data['orderIds'])->orWhereIn('public_id', $data['orderIds']))
                ->get();

            // Every requested id must resolve — no silent drops in the reported count.
            $resolved = $ops->pluck('id')->merge($ops->pluck('public_id'))->all();
            $missing = array_values(array_diff($data['orderIds'], $resolved));
            if (! empty($missing)) {
                return $this->fail('CONSOLIDATION_FAILED', 'Some orders were not found',
                    'بعض الطلبات غير موجودة', ['missing' => $missing], 422);
            }
            $invalid = $ops->filter(fn ($o) => $o->status !== Operation::STATUS_PENDING)->pluck('id')->values()->all();
            if (! empty($invalid)) {
                return $this->fail('CONSOLIDATION_FAILED', 'Only pending orders can be consolidated',
                    'يمكن تجميع الطلبات المعلقة فقط', ['invalid' => $invalid], 422);
            }

            $groupId = (string) Str::uuid();
            DB::transaction(function () use ($ops, $groupId, $data) {
                foreach ($ops as $op) {
                    $payload = $op->payload ?? [];
                    $payload['consolidatedGroupId'] = $groupId;
                    $payload['supplierId'] = $data['supplierId'];
                    $op->update(['status' => 'approved', 'payload' => $payload]);
                }
            });

            return $this->created(['consolidatedGroupId' => $groupId, 'orderCount' => $ops->count()]);
        });
    }

    public function send(Request $request, string $groupId): JsonResponse
    {
        return $this->run(function () use ($groupId) {
            $affected = Operation::where('module_key', 'purchases')
                ->where('payload->consolidatedGroupId', $groupId)->get();

            if ($affected->isEmpty()) {
                return $this->fail('NOT_FOUND', 'Consolidation group not found',
                    'مجموعة التجميع غير موجودة', ['groupId' => $groupId], 404);
            }

            $sentAt = now()->toIso8601String();
            $batchId = 'PO-BATCH-'.strtoupper(Str::random(8));
            DB::transaction(function () use ($affected, $sentAt, $batchId) {
                foreach ($affected as $op) {
                    // Stamp sentAt + a public batch id so the "sent" listings surface them.
                    $payload = $op->payload ?? [];
                    $payload['sentAt'] = $sentAt;
                    $payload['poBatchId'] = $batchId;
                    $op->update(['status' => 'final-approved', 'payload' => $payload]);
                }
            });

            return $this->ok(['groupId' => $groupId, 'batchId' => $batchId, 'sent' => $affected->count()]);
        });
    }

    /**
     * Suppliers list — reads the SAME store the supplier CRUD and the Excel
     * export use (asab_suppliers), so created suppliers actually appear here.
     * Response keys are a superset of the old {id, name, category} shape.
     */
    public function suppliers(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = \Modules\Admin\Models\AsabSupplier::query()->orderBy('name');

            if ($search = $request->query('search')) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%"));
            }
            if ($category = $request->query('category')) {
                $q->where('category', $category);
            }
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }

            $perPage = min((int) $request->query('pageSize', 50), 100);
            $p = $q->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            // ACC-3.2 supplier cards need items + monthly orders. Grouped queries
            // over the page's ids — never a per-row lookup. Order tally is grouped
            // in PHP off the decoded payload to sidestep driver-specific JSON quoting.
            $ids = collect($p->items())->pluck('id');
            $legacyIds = collect($p->items())->pluck('legacy_supplier_id')->filter()->unique();
            $itemsCount = \Modules\Admin\Models\SupplierItem::whereIn('supplier_id', $ids)
                ->selectRaw('supplier_id, COUNT(*) as c')->groupBy('supplier_id')->pluck('c', 'supplier_id');

            // Operations-family purchases keyed by payload.supplierId (halalas).
            $opRows = \Modules\Admin\Models\Operation::where('module_key', 'purchases')
                ->whereIn('payload->supplierId', $ids)->limit(10000)->get(['payload', 'amount', 'operation_date'])
                ->groupBy(fn ($o) => $o->payload['supplierId'] ?? null);
            // PRC-3.2 lifetime figures from the REAL (bridge) pipeline, keyed by legacy supplier id (SAR).
            $bridgeStats = PurchaseOrder::whereIn('supplier_id', $legacyIds)
                ->selectRaw('supplier_id, COUNT(*) as c, COALESCE(SUM(total_amount), 0) as spend')
                ->groupBy('supplier_id')->get()->keyBy('supplier_id');

            $rows = array_map(function ($s) use ($itemsCount, $opRows, $bridgeStats) {
                $ops = $opRows[$s->id] ?? collect();
                $bridge = $s->legacy_supplier_id ? ($bridgeStats[$s->legacy_supplier_id] ?? null) : null;
                $monthly = $ops->filter(fn ($o) => $o->operation_date && $o->operation_date->gte(now()->subDays(30)))->count();

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'category' => $s->category,
                    'contactName' => $s->contact_name,
                    'contactPhone' => $s->contact_phone,
                    'contactEmail' => $s->contact_email,
                    'paymentTerms' => $s->payment_terms,
                    'rating' => (int) ($s->rating ?? 0),   // 0–50 scale (stars × 10)
                    'status' => $s->status,
                    'isActive' => $s->status === 'active',
                    'isExternal' => (bool) $s->is_external,   // §10.2 internal vs external
                    'supplierKind' => $s->is_external ? 'external' : 'internal',
                    'itemsCount' => (int) ($itemsCount[$s->id] ?? 0),
                    'monthlyOrderCount' => $monthly,
                    // PRC-3.2 — bridge orders (SAR) + Operations orders (halalas → SAR).
                    'lifetimeOrdersCount' => (int) ($bridge->c ?? 0) + $ops->count(),
                    'lifetimeSpend' => round((float) ($bridge->spend ?? 0) + $ops->sum('amount') / 100, 2),
                ];
            }, $p->items());

            return $this->paginated($p, $rows, ['kpis' => $this->supplierKpis()]);
        });
    }

    /**
     * PRC-3.2 supplier-KPI block: active count, total procurement purchases
     * (bridge SAR + Operations halalas→SAR), average rating in stars (0–5).
     * Tenant-scoped by the AsabSupplier/Operation global scopes.
     *
     * @return array{activeSuppliers:int, totalPurchases:float, avgRating:float}
     */
    private function supplierKpis(): array
    {
        $tenantLegacyIds = \Modules\Admin\Models\AsabSupplier::whereNotNull('legacy_supplier_id')->pluck('legacy_supplier_id');
        $bridgeTotal = (float) PurchaseOrder::whereIn('supplier_id', $tenantLegacyIds)->sum('total_amount');
        $opsTotal = (int) Operation::where('module_key', 'purchases')->sum('amount') / 100;

        return [
            'activeSuppliers' => \Modules\Admin\Models\AsabSupplier::where('status', 'active')->count(),
            'totalPurchases' => round($bridgeTotal + $opsTotal, 2),
            'avgRating' => round(((float) \Modules\Admin\Models\AsabSupplier::avg('rating')) / 10, 2),
        ];
    }

    /**
     * Items catalog list — reads the SAME store the item CRUD and the Excel
     * export use (asab_supplier_items), so created/imported items actually
     * appear here. Response keys are a superset of the old {id, name} shape.
     */
    public function items(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = \Modules\Admin\Models\SupplierItem::query()
                ->when($request->user()->company_id, fn ($w, $companyId) => $w->where('company_id', $companyId))
                ->orderBy('name');

            if ($search = $request->query('search')) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%"));
            }
            if ($category = $request->query('category')) {
                $q->where('category', $category);
            }
            if ($supplier = $request->query('supplierId')) {
                $q->where('supplier_id', $supplier);
            }

            $perPage = min((int) $request->query('pageSize', 50), 100);
            $p = $q->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            $itemIds = collect($p->items())->pluck('id');
            $supplierNames = \Modules\Admin\Models\AsabSupplier::whereIn(
                'id', collect($p->items())->pluck('supplier_id')->filter()->unique(),
            )->pluck('name', 'id');
            $brandNames = \Modules\Admin\Models\AsabBrand::whereIn(
                'id', collect($p->items())->pluck('brand_id')->filter()->unique(),
            )->pluck('name', 'id');
            // PRC-3.1 «عدد الموردين» — distinct suppliers offering the item, drawn
            // from priced history rows (one grouped query, never per-row).
            $priceSuppliers = \Modules\Admin\Models\ProcurementItemPrice::whereIn('item_id', $itemIds)
                ->whereNotNull('supplier_id')->get(['item_id', 'supplier_id'])
                ->groupBy('item_id')->map(fn ($rows) => $rows->pluck('supplier_id')->unique());

            return $this->paginated($p, array_map(function ($i) use ($supplierNames, $brandNames, $priceSuppliers) {
                $suppliers = collect($priceSuppliers[$i->id] ?? []);
                if ($i->supplier_id) {
                    $suppliers = $suppliers->push($i->supplier_id)->unique();
                }

                return [
                    'id' => $i->id,
                    'code' => $i->code,
                    'name' => $i->name,
                    'unit' => $i->unit,
                    'category' => $i->category,
                    'brandId' => $i->brand_id,
                    'brandName' => $i->brand_id ? ($brandNames[$i->brand_id] ?? null) : null,
                    'supplierId' => $i->supplier_id,
                    'supplierName' => $i->supplier_id ? ($supplierNames[$i->supplier_id] ?? null) : null,
                    'supplierCount' => $suppliers->count(),
                    'lastPriceHalalas' => (int) $i->price,
                    'available' => (bool) $i->available,
                    'status' => $i->status,
                ];
            }, $p->items()));
        });
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
            'operationDate' => optional($o->operation_date)->toIso8601String(),
        ] + $this->urgencyOf($o);
    }

    /**
     * T11.1 — urgency is driven by the payload the branch/create-modal sets
     * (`normal|urgent`), NOT the reconciliation `match` field (which the factory
     * always writes as `exact`, so «عاجل» never rendered before).
     *
     * @return array{urgency:string, urgencyLabel:string}
     */
    private function urgencyOf(Operation $o): array
    {
        $urgent = ($o->payload['urgency'] ?? 'normal') === 'urgent';

        return ['urgency' => $urgent ? 'urgent' : 'normal', 'urgencyLabel' => $urgent ? 'عاجل' : 'عادي'];
    }
}
