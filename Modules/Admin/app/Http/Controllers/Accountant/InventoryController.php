<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\BranchInventoryList;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\InventoryReconciliationService;
use Modules\Admin\Services\InventoryReviewService;
use Modules\Admin\Services\NotificationService;
use Modules\Admin\Services\RealtimeBroadcaster;

/**
 * Accountant inventory review + per-branch daily-list management
 * (BACKEND_API_SPEC.md §6.3.5 / §6.3.6).
 */
class InventoryController extends AsabController
{
    public function __construct(
        private readonly InventoryReconciliationService $reconciliation,
        private readonly InventoryReviewService $review,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $type = in_array($request->query('type'), ['daily', 'monthly'], true) ? $request->query('type') : 'monthly';
            $companyId = $request->user()->company_id;
            $branchIds = $this->assignedBranchIds();
            if ($branch = $request->query('branchId')) {
                $this->assertBranchAssigned($branch);
                $branchIds = [$branch];
            }

            return $this->ok($this->review->overview($companyId, $branchIds, $type));
        });
    }

    public function flagBranch(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $data = $request->validate(['flagged' => 'required|boolean']);
            $this->assertBranchAssigned($branchId);
            $op = Operation::where('module_key', 'inventory')->where('branch_id', $branchId)->latest('operation_date')->firstOrFail();
            $payload = $op->payload ?? [];
            $payload['isFlagged'] = $data['flagged'];
            $op->update(['payload' => $payload]);

            return $this->ok(['branchId' => $branchId, 'isFlagged' => $data['flagged']]);
        });
    }

    public function flagItems(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $data = $request->validate([
                // 'itemIndices' is canonical; 'itemIndexes' is the doc alias — accept either.
                'itemIndices' => 'required_without:itemIndexes|array',
                'itemIndexes' => 'required_without:itemIndices|array',
                'note' => 'sometimes|nullable|string',
            ]);
            $indices = $data['itemIndices'] ?? $data['itemIndexes'];
            $this->assertBranchAssigned($branchId);
            $op = Operation::where('module_key', 'inventory')->where('branch_id', $branchId)->latest('operation_date')->firstOrFail();
            $payload = $op->payload ?? [];
            $payload['flaggedItemIndices'] = $indices;
            if (array_key_exists('note', $data) && $data['note'] !== null) {
                $payload['flagNote'] = $data['note'];
            }
            $op->update(['payload' => $payload]);

            return $this->ok(array_filter([
                'branchId' => $branchId,
                'flaggedItemIndices' => $indices,
                'note' => $data['note'] ?? null,
            ], fn ($v) => $v !== null));
        });
    }

    public function sendConfirmation(Request $request, RealtimeBroadcaster $rt, NotificationService $notifications, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $notifications, $branchId) {
            $this->assertBranchAssigned($branchId);
            $op = Operation::where('module_key', 'inventory')->where('branch_id', $branchId)->latest('operation_date')->firstOrFail();
            $payload = $op->payload ?? [];
            $payload['sentToConfirm'] = true;
            $payload['sentToConfirmAt'] = now()->toIso8601String();
            $op->update(['payload' => $payload]);

            // T07.9 — the accountant «إرسال» must actually reach the branch: a
            // durable notification the branch manager sees offline, plus the
            // realtime nudge (same event the company surface emits).
            $rt->inventoryFlagSent($branchId, $payload['flaggedItemIndices'] ?? []);
            $notifications->pushToBranch(
                $request->user()->company_id, $branchId, 'branch', 'inventory.flagged',
                'أصناف بحاجة إلى مراجعة الجرد', 'راجع الأصناف المُعلَّمة وأكِّد الجرد',
                null, ['type' => 'operation', 'id' => $op->id],
            );

            return $this->ok(['branchId' => $branchId, 'sentToConfirm' => true, 'notifiedBranch' => true]);
        });
    }

    /**
     * GET inventory/brands — the brand pills of «تحديد الأصناف للجرد»
     * (meeting 2026-07-30: «العلامات التجارية مش راجعة»). Accountant-scoped;
     * an empty scope returns 200 + [] so the FE can render an empty state.
     */
    public function brands(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $ids = $this->assignedBrandIds();

            $brands = \Modules\Admin\Models\AsabBrand::query()
                ->where('company_id', $request->user()->company_id)
                ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
                ->orderBy('name')
                ->get(['id', 'name', 'abbr']);

            $branchCounts = \Modules\Branch\Models\Branch::query()
                ->whereIn('asab_brand_id', $brands->pluck('id'))
                ->selectRaw('asab_brand_id, count(*) as c')
                ->groupBy('asab_brand_id')
                ->pluck('c', 'asab_brand_id');

            $itemCounts = InventoryCatalogItem::query()
                ->whereIn('brand_id', $brands->pluck('id'))
                ->where('type', InventoryCatalogItem::TYPE_SALES_ITEM)
                ->selectRaw('brand_id, count(*) as c')
                ->groupBy('brand_id')
                ->pluck('c', 'brand_id');

            return $this->ok($brands->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'abbr' => $b->abbr,
                'branchCount' => (int) ($branchCounts[$b->id] ?? 0),
                'itemCount' => (int) ($itemCounts[$b->id] ?? 0),
            ])->values()->all());
        });
    }

    /**
     * GET inventory/brands/{brandId}/branches — the branch pills after a brand
     * is picked; intersected with the caller's assigned branch set.
     */
    public function brandBranches(Request $request, string $brandId): JsonResponse
    {
        return $this->run(function () use ($brandId) {
            $this->assertBrandAssigned($brandId);

            $restaurantIds = \Modules\Admin\Models\AsabRestaurant::withoutGlobalScopes()
                ->where('brand_id', $brandId)->pluck('id');

            $q = \Modules\Branch\Models\Branch::query()
                ->where(function ($w) use ($brandId, $restaurantIds) {
                    $w->where('asab_brand_id', $brandId);
                    if ($restaurantIds->isNotEmpty()) {
                        $w->orWhereIn('asab_restaurant_id', $restaurantIds);
                    }
                });

            if (($assigned = $this->assignedBranchIds()) !== null) {
                $q->whereIn('id', $assigned);
            }

            $branches = $q->orderBy('name')->get(['id', 'name', 'asab_restaurant_id']);

            $listCounts = BranchInventoryList::query()
                ->whereIn('branch_id', $branches->pluck('id'))
                ->selectRaw('branch_id, count(*) as c')
                ->groupBy('branch_id')
                ->pluck('c', 'branch_id');

            return $this->ok($branches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'restaurantId' => $b->asab_restaurant_id,
                'listItemCount' => (int) ($listCounts[$b->id] ?? 0),
            ])->values()->all());
        });
    }

    public function catalog(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            // Inventory catalog = sales items; uploaded raw materials belong to
            // the purchasing module and are excluded unless explicitly requested.
            $q = InventoryCatalogItem::where('type', $request->query('type', InventoryCatalogItem::TYPE_SALES_ITEM));
            // The catalog table has no tenant scope: pin reads to the caller's brands.
            if (($brandIds = $this->assignedBrandIds()) !== null) {
                $q->whereIn('brand_id', $brandIds);
            }
            if ($brand = $request->query('brandId')) {
                $q->where('brand_id', $brand);
            }
            if ($cat = $request->query('category')) {
                $q->where('category', $cat);
            }
            // ACC-4.5 item-selection search (matches the name, ar/en).
            if ($search = $request->query('search')) {
                $q->where('name', 'like', "%{$search}%");
            }
            $items = $q->orderBy('category')->orderBy('name')->get();

            return $this->ok([
                'categories' => $items->pluck('category')->unique()->values()->all(),
                'items' => $items->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'cat' => $i->category, 'unit' => $i->unit])->all(),
            ]);
        });
    }

    public function storeCatalogItem(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'brandId' => 'required|string',
                'name' => 'required|string|max:200',
                'category' => 'required|string|max:80',
                'unit' => 'required|string|max:16',
            ]);
            $this->assertBrandAssigned($data['brandId']);
            $item = InventoryCatalogItem::create([
                'brand_id' => $data['brandId'], 'name' => $data['name'],
                'category' => $data['category'], 'unit' => $data['unit'], 'status' => 'active',
            ]);

            return $this->created(['id' => $item->id, 'name' => $item->name]);
        });
    }

    /**
     * PUT /inventory/catalog — create catalog items from full definitions (if missing),
     * link them to a branch's daily list, and notify the branch.
     * Body: {branchId, items:[{name,category,unit}]}.
     */
    public function storeCatalog(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt): JsonResponse
    {
        return $this->run(function () use ($request, $rt) {
            $data = $request->validate([
                'branchId' => 'required|string',
                'items' => 'required|array|min:1',
                'items.*.name' => 'required|string|max:200',
                'items.*.category' => 'required|string|max:80',
                'items.*.unit' => 'required|string|max:16',
            ]);
            $branchId = $data['branchId'];
            $this->assertBranchAssigned($branchId);

            // Resolve the brand for real — brandIdForBranch() returns the
            // literal 'unknown' for an unlinked branch, poisoning catalog rows
            // that no scoped read could ever return.
            $branch = \Modules\Branch\Models\Branch::whereKey($branchId)
                ->first(['id', 'asab_brand_id', 'asab_restaurant_id']);
            $brandId = $branch?->asab_brand_id
                ?? ($branch?->asab_restaurant_id
                    ? \Modules\Admin\Models\AsabRestaurant::withoutGlobalScopes()
                        ->whereKey($branch->asab_restaurant_id)->value('brand_id')
                    : null);

            if ($brandId === null) {
                throw new \Modules\Admin\Exceptions\AsabException(
                    'BRANCH_UNLINKED',
                    'Branch is not linked to a brand',
                    'الفرع غير مرتبط بعلامة تجارية — اربط الفرع أولاً من إدارة الفروع',
                    422,
                );
            }

            $result = DB::transaction(function () use ($data, $branchId, $brandId, $request) {
                $out = [];
                foreach ($data['items'] as $row) {
                    // Create from the full definition only when no matching catalog item exists for the brand.
                    $item = InventoryCatalogItem::firstOrCreate(
                        ['brand_id' => $brandId, 'name' => $row['name'], 'category' => $row['category']],
                        ['unit' => $row['unit'], 'status' => 'active'],
                    );
                    // Link to the branch's daily list (idempotent).
                    BranchInventoryList::firstOrCreate(
                        ['branch_id' => $branchId, 'catalog_item_id' => $item->id],
                        ['added_by_id' => $request->user()->id],
                    );
                    $out[] = [
                        'id' => $item->id,
                        'name' => $item->name,
                        'category' => $item->category,
                        'unit' => $item->unit,
                    ];
                }

                return $out;
            });

            $rt->inventoryFlagSent($branchId, $result);

            return $this->created(['branchId' => $branchId, 'items' => $result, 'notifiedBranch' => true]);
        });
    }

    public function dailyList(string $branchId): JsonResponse
    {
        return $this->run(function () use ($branchId) {
            $this->assertBranchAssigned($branchId);
            $rows = BranchInventoryList::where('branch_id', $branchId)->get();
            $catalog = InventoryCatalogItem::whereIn('id', $rows->pluck('catalog_item_id'))->get()->keyBy('id');

            return $this->listResponse($rows->map(fn ($r) => [
                'id' => $r->id,
                'catalogItemId' => $r->catalog_item_id,
                'name' => optional($catalog->get($r->catalog_item_id))->name,
                'isFlagged' => (bool) $r->is_flagged,
            ])->all());
        });
    }

    public function saveDailyList(Request $request, RealtimeBroadcaster $rt, NotificationService $notifications, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $notifications, $branchId) {
            $data = $request->validate(['items' => 'required|array', 'items.*' => 'string']);
            $this->assertBranchAssigned($branchId);

            // A repeated id in one payload would hit the (branch_id,
            // catalog_item_id) unique index and 500 — dedupe, order preserved;
            // savedCount reports the rows actually stored.
            $itemIds = array_values(array_unique($data['items']));

            DB::transaction(function () use ($branchId, $itemIds, $request) {
                BranchInventoryList::where('branch_id', $branchId)->delete();
                foreach ($itemIds as $catalogItemId) {
                    BranchInventoryList::create([
                        'branch_id' => $branchId,
                        'catalog_item_id' => $catalogItemId,
                        'added_by_id' => $request->user()->id,
                    ]);
                }
            });

            // T07.1 / MOB-1.2 — «حفظ وتحديث التطبيق فوراً»: the branch app must
            // learn its count list changed. Durable notification + realtime event;
            // `pushedAt` reflects a push that actually happened.
            $rt->inventoryDailyListUpdated($branchId, count($itemIds));
            $notifications->pushToBranch(
                $request->user()->company_id, $branchId, 'branch', 'inventory.daily_list_updated',
                'تم تحديث قائمة الجرد اليومي', 'قائمة أصناف الجرد لديك تم تحديثها',
                null, ['type' => 'branch', 'id' => $branchId],
            );

            return $this->ok(['savedCount' => count($itemIds), 'pushedAt' => now()->toIso8601String()]);
        });
    }

    /** GET /accountant/inventory/branches/{branchId}/daily-reconciliation?date= (MISSING_Dashboard §9.1). */
    public function dailyReconciliation(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $this->assertBranchAssigned($branchId);
            $date = $request->query('date', now()->toDateString());

            return $this->ok($this->reconciliation->snapshot($request->user()->company_id, $branchId, $date));
        });
    }

    /** POST /accountant/inventory/branches/{branchId}/daily-variance-allocation (MISSING_Dashboard §9.2). */
    public function saveDailyVarianceAllocation(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $data = $request->validate([
                'date' => 'required|date',
                'items' => 'required|array|min:1',
                'items.*.itemId' => 'required|string',
                'items.*.allocations' => 'required|array|min:1',
                'items.*.allocations.*.employeeId' => 'required|string',
                'items.*.allocations.*.qty' => 'required|numeric|min:0',
            ]);
            $this->assertBranchAssigned($branchId);

            return $this->ok($this->reconciliation->allocate(
                $request->user()->company_id,
                $branchId,
                $data['date'],
                $data['items'],
                $request->user()->id,
            ));
        });
    }
}
