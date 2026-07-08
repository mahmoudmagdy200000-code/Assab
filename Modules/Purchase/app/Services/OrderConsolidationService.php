<?php

namespace Modules\Purchase\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\Priority;
use Modules\Purchase\Exceptions\PurchaseOrderException;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderGroup;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierProduct;

/**
 * Consolidation of manager-approved (CONFIRMED, ungrouped) mobile purchase
 * orders for the purchasing dashboard: live aggregation per supplier/city
 * with capacity checks against the supplier's own declared stock
 * (supplier_products.stock_quantity), then batch "send" that stamps a
 * PurchaseOrderGroup for tracking. Orders stay the single state machine.
 */
class OrderConsolidationService
{
    /** Order types routed to suppliers (internal transfers are branch-side). */
    private const SUPPLIER_BOUND = ['direct_supplier', 'via_purchasing_officer', 'multiple_sources'];

    /** Item-line statuses that count toward supplier quantities. */
    private const COUNTED_ITEM_STATUSES = [
        OrderItemStatus::CONFIRMED,
        OrderItemStatus::PARTIAL,
        OrderItemStatus::PARTIAL_CONFIRMATION,
        OrderItemStatus::CONFIRMED_NEED_TIME,
        OrderItemStatus::CONFIRMED_ALTERNATIVE_PRODUCT,
    ];

    /**
     * Aggregation of consolidatable orders grouped by supplier, with per-item
     * totals vs the supplier's declared capacity.
     *
     * @param  string[]|null  $branchIds  tenant scope (null = unrestricted)
     */
    public function previewBySupplier(?array $branchIds): array
    {
        $orders = $this->consolidatable($branchIds)->get();

        $unassigned = $orders->whereNull('supplier_id');
        $names = Supplier::whereIn('id', $orders->pluck('supplier_id')->filter()->unique())
            ->pluck('name', 'id');

        $suppliers = $orders->whereNotNull('supplier_id')
            ->groupBy('supplier_id')
            ->map(function (Collection $group, string $supplierId) use ($names) {
                $items = $this->aggregateItems($group, $supplierId);

                return [
                    'supplierId' => $supplierId,
                    'supplierName' => $names[$supplierId] ?? '—',
                    'ordersCount' => $group->count(),
                    'branchesCount' => $group->pluck('branch_id')->unique()->count(),
                    'cities' => $group->pluck('branch.city')->filter()->unique()->values()->all(),
                    'urgentCount' => $group->filter(fn ($o) => $o->priority === Priority::HIGH)->count(),
                    'totalAmount' => (float) $group->sum('total_amount'),
                    'itemsCount' => count($items),
                    'capacityExceeded' => collect($items)->contains(fn ($i) => $i['exceeded'] === true),
                    'items' => $items,
                    'orderIds' => $group->pluck('id')->all(),
                ];
            })->values()->all();

        return [
            'suppliers' => $suppliers,
            // Confirmed officer orders with no supplier yet: pick a supplier at send time.
            'unassignedOrders' => $unassigned->map(fn (PurchaseOrder $o) => [
                'id' => $o->id,
                'orderNumber' => $o->order_number,
                'branchName' => $o->branch?->name,
                'totalAmount' => (float) $o->total_amount,
            ])->values()->all(),
        ];
    }

    /** @param  string[]|null  $branchIds */
    public function previewByCity(?array $branchIds): array
    {
        $orders = $this->consolidatable($branchIds)->get();
        $names = Supplier::whereIn('id', $orders->pluck('supplier_id')->filter()->unique())
            ->pluck('name', 'id');

        return $orders->groupBy(fn (PurchaseOrder $o) => $o->branch?->city ?? 'غير محدد')
            ->map(fn (Collection $group, string $city) => [
                'city' => $city,
                'ordersCount' => $group->count(),
                'urgentCount' => $group->filter(fn ($o) => $o->priority === Priority::HIGH)->count(),
                'totalAmount' => (float) $group->sum('total_amount'),
                'suppliers' => $group->pluck('supplier_id')->filter()->unique()
                    ->map(fn ($id) => $names[$id] ?? '—')->values()->all(),
                'orderIds' => $group->pluck('id')->all(),
            ])->values()->all();
    }

    /**
     * Send a supplier's consolidation batch: stamp a group over the orders
     * (assigning the supplier to officer orders that have none yet).
     *
     * @param  string[]|null  $orderIds  explicit subset; null = all consolidatable for the supplier
     * @param  string[]|null  $branchIds  tenant scope
     */
    public function send(string $supplierId, ?array $orderIds, string $asabUserId, ?array $branchIds): PurchaseOrderGroup
    {
        Supplier::findOrFail($supplierId);

        return DB::transaction(function () use ($supplierId, $orderIds, $asabUserId, $branchIds) {
            $q = $this->consolidatable($branchIds)->lockForUpdate();

            $orders = $orderIds === null
                ? $q->where('supplier_id', $supplierId)->get()
                : $q->whereIn('id', $orderIds)->get();

            if ($orderIds !== null && count($orderIds) !== $orders->count()) {
                $missing = array_diff($orderIds, $orders->pluck('id')->all());
                throw PurchaseOrderException::itemsNotInOrder(array_values($missing));
            }

            $mismatched = $orders->first(fn (PurchaseOrder $o) => $o->supplier_id !== null && $o->supplier_id !== $supplierId);
            if ($mismatched !== null) {
                throw PurchaseOrderException::supplierMismatch($mismatched->order_number);
            }
            if ($orders->isEmpty()) {
                throw PurchaseOrderException::nothingToConsolidate();
            }

            $group = PurchaseOrderGroup::create([
                'supplier_id' => $supplierId,
                'created_by_asab_user_id' => $asabUserId,
                'sent_at' => now(),
            ]);

            foreach ($orders as $order) {
                $order->update(['group_id' => $group->id, 'supplier_id' => $supplierId]);
            }

            return $group->load('orders');
        });
    }

    /**
     * Sent batches visible to this tenant, newest first, with derived
     * tracking status.
     *
     * @param  string[]|null  $branchIds
     */
    public function sentGroups(?array $branchIds): array
    {
        return PurchaseOrderGroup::with(['orders', 'supplier:id,name'])
            ->when($branchIds !== null, fn ($q) => $q->whereHas('orders', fn ($o) => $o->whereIn('branch_id', $branchIds)))
            ->orderByDesc('sent_at')
            ->limit(100)
            ->get()
            ->map(fn (PurchaseOrderGroup $g) => [
                'groupId' => $g->id,
                'groupNumber' => $g->group_number,
                'supplierId' => $g->supplier_id,
                'supplierName' => $g->supplier?->name ?? '—',
                'ordersCount' => $g->orders->count(),
                'totalAmount' => (float) $g->orders->sum('total_amount'),
                'status' => $g->deriveStatus(),
                'sentAt' => optional($g->sent_at)->toIso8601String(),
            ])->all();
    }

    /**
     * One batch with its member orders and the same per-item aggregation as
     * the preview (for the tracking / details screen).
     *
     * @param  string[]|null  $branchIds
     */
    public function groupDetails(string $groupId, ?array $branchIds): array
    {
        $group = PurchaseOrderGroup::with(['orders.branch:id,name,city', 'orders.items', 'supplier:id,name'])
            ->when($branchIds !== null, fn ($q) => $q->whereHas('orders', fn ($o) => $o->whereIn('branch_id', $branchIds)))
            ->findOrFail($groupId);

        return [
            'groupId' => $group->id,
            'groupNumber' => $group->group_number,
            'supplierId' => $group->supplier_id,
            'supplierName' => $group->supplier?->name ?? '—',
            'status' => $group->deriveStatus(),
            'sentAt' => optional($group->sent_at)->toIso8601String(),
            'totalAmount' => (float) $group->orders->sum('total_amount'),
            'items' => $this->aggregateItems($group->orders, $group->supplier_id),
            'orders' => $group->orders->map(fn (PurchaseOrder $o) => [
                'id' => $o->id,
                'orderNumber' => $o->order_number,
                'branchId' => $o->branch_id,
                'branchName' => $o->branch?->name,
                'city' => $o->branch?->city,
                'status' => $o->status?->value,
                'statusLabel' => $o->status_label,
                'totalAmount' => (float) $o->total_amount,
            ])->values()->all(),
        ];
    }

    /** Confirmed, ungrouped, supplier-bound orders inside the tenant scope. */
    private function consolidatable(?array $branchIds): Builder
    {
        return PurchaseOrder::query()
            ->with(['branch:id,name,city', 'items'])
            ->whereIn('order_type', self::SUPPLIER_BOUND)
            ->where('status', OrderStatus::CONFIRMED)
            ->whereNull('group_id')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds));
    }

    /**
     * Sum confirmed line quantities across orders per catalog item and rate
     * them against the supplier's declared stock (null capacity = supplier
     * has not declared stock for the item).
     *
     * @param  Collection<int, PurchaseOrder>  $orders
     */
    private function aggregateItems(Collection $orders, ?string $supplierId): array
    {
        $lines = $orders->flatMap->items
            ->filter(fn ($i) => in_array($i->status, self::COUNTED_ITEM_STATUSES, true));

        $totals = [];
        foreach ($lines as $line) {
            $key = $line->item_id ?? 'name:'.$line->item_name;
            $qty = (float) ($line->quantity_confirmed ?? $line->quantity_ordered);
            $totals[$key] ??= [
                'itemId' => $line->item_id,
                'name' => $line->item_name,
                'unit' => $line->unit_of_measurement,
                'totalQuantity' => 0.0,
                'unitPrice' => 0.0,
            ];
            $totals[$key]['totalQuantity'] += $qty;
            $totals[$key]['unitPrice'] = max($totals[$key]['unitPrice'], (float) $line->unit_price);
        }

        $capacities = $supplierId === null ? collect() : SupplierProduct::where('supplier_id', $supplierId)
            ->whereIn('item_id', collect($totals)->pluck('itemId')->filter())
            ->pluck('stock_quantity', 'item_id');

        return array_values(array_map(function (array $row) use ($capacities) {
            $capacity = $row['itemId'] !== null ? (float) ($capacities[$row['itemId']] ?? 0) : 0.0;
            $hasCapacity = $capacity > 0;

            return $row + [
                'capacity' => $hasCapacity ? $capacity : null,
                'capacityPct' => $hasCapacity ? (int) round($row['totalQuantity'] / $capacity * 100) : null,
                'exceeded' => $hasCapacity ? $row['totalQuantity'] > $capacity : null,
                'excessQuantity' => $hasCapacity && $row['totalQuantity'] > $capacity
                    ? round($row['totalQuantity'] - $capacity, 3)
                    : null,
            ];
        }, $totals));
    }
}
