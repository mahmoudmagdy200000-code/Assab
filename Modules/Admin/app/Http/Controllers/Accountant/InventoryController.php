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

/**
 * Accountant inventory review + per-branch daily-list management
 * (BACKEND_API_SPEC.md §6.3.5 / §6.3.6).
 */
class InventoryController extends AsabController
{
    public function __construct(private readonly InventoryReconciliationService $reconciliation) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $type = $request->query('type', 'monthly');
            $q = Operation::where('module_key', 'inventory');
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            $ops = $q->orderByDesc('operation_date')->get();

            $branches = $ops->groupBy('branch_id')->map(function ($group, $branchId) {
                $op = $group->first();
                $items = $op->payload['items'] ?? [];

                return [
                    'branchId' => $branchId,
                    'operationId' => $op->id,
                    'status' => $op->status,
                    'items' => $items,
                    'anomalyCount' => collect($items)->where('isAnomaly', true)->count(),
                    'isFlagged' => (bool) ($op->payload['isFlagged'] ?? false),
                    'branchConfirmed' => (bool) ($op->payload['branchReconfirmedAt'] ?? false),
                    'flaggedItemIndices' => $op->payload['flaggedItemIndices'] ?? [],
                ];
            })->values()->all();

            return $this->ok([
                'branches' => $branches,
                'summary' => [
                    'totalSubmissions' => $ops->count(),
                    'pendingCount' => $ops->where('status', 'pending')->count(),
                    'completedBranches' => collect($branches)->where('status', 'final-approved')->count(),
                ],
            ]);
        });
    }

    public function flagBranch(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $data = $request->validate(['flagged' => 'required|boolean']);
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
            $data = $request->validate(['itemIndices' => 'required|array']);
            $op = Operation::where('module_key', 'inventory')->where('branch_id', $branchId)->latest('operation_date')->firstOrFail();
            $payload = $op->payload ?? [];
            $payload['flaggedItemIndices'] = $data['itemIndices'];
            $op->update(['payload' => $payload]);

            return $this->ok(['branchId' => $branchId, 'flaggedItemIndices' => $data['itemIndices']]);
        });
    }

    public function sendConfirmation(string $branchId): JsonResponse
    {
        return $this->run(function () use ($branchId) {
            $op = Operation::where('module_key', 'inventory')->where('branch_id', $branchId)->latest('operation_date')->firstOrFail();
            $payload = $op->payload ?? [];
            $payload['sentToConfirm'] = true;
            $payload['sentToConfirmAt'] = now()->toIso8601String();
            $op->update(['payload' => $payload]);

            return $this->ok(['branchId' => $branchId, 'sentToConfirm' => true]);
        });
    }

    public function catalog(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = InventoryCatalogItem::query();
            if ($brand = $request->query('brandId')) {
                $q->where('brand_id', $brand);
            }
            if ($cat = $request->query('category')) {
                $q->where('category', $cat);
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
            $item = InventoryCatalogItem::create([
                'brand_id' => $data['brandId'], 'name' => $data['name'],
                'category' => $data['category'], 'unit' => $data['unit'], 'status' => 'active',
            ]);

            return $this->created(['id' => $item->id, 'name' => $item->name]);
        });
    }

    public function dailyList(string $branchId): JsonResponse
    {
        return $this->run(function () use ($branchId) {
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

    public function saveDailyList(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $data = $request->validate(['items' => 'required|array', 'items.*' => 'string']);

            DB::transaction(function () use ($branchId, $data, $request) {
                BranchInventoryList::where('branch_id', $branchId)->delete();
                foreach ($data['items'] as $catalogItemId) {
                    BranchInventoryList::create([
                        'branch_id' => $branchId,
                        'catalog_item_id' => $catalogItemId,
                        'added_by_id' => $request->user()->id,
                    ]);
                }
            });

            return $this->ok(['savedCount' => count($data['items']), 'pushedAt' => now()->toIso8601String()]);
        });
    }

    /** GET /accountant/inventory/branches/{branchId}/daily-reconciliation?date= (MISSING_Dashboard §9.1). */
    public function dailyReconciliation(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
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
