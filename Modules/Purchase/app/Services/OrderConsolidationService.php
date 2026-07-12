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

    /** Statuses of a purchase order still awaiting a purchasing-manager decision. */
    private const INCOMING_STATUSES = [OrderStatus::PENDING, OrderStatus::EMERGENCY, OrderStatus::VARIANCE];

    public function __construct(private readonly CalculationService $calc) {}

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
     * PRC-2.1 «التجميع حسب الصنف» — the SRS core value loop. Consolidatable
     * orders regrouped BY catalog item across branches: one card per item with
     * per-branch quantity lines, the cheapest active supplier as the suggested
     * source, and the savings that switching to it would yield.
     *
     * @param  string[]|null  $branchIds  tenant scope
     */
    public function previewByItem(?array $branchIds): array
    {
        $orders = $this->consolidatable($branchIds)->get();

        $items = [];
        foreach ($orders as $order) {
            $lines = $order->items->filter(fn ($i) => in_array($i->status, self::COUNTED_ITEM_STATUSES, true));
            foreach ($lines as $line) {
                $key = $line->item_id ?? 'name:'.$line->item_name;
                $qty = (float) ($line->quantity_confirmed ?? $line->quantity_ordered);
                $items[$key] ??= [
                    'itemId' => $line->item_id,
                    'name' => $line->item_name,
                    'unit' => $line->unit_of_measurement,
                    'totalQuantity' => 0.0,
                    'currentUnitPrice' => 0.0,
                    'orderIds' => [],
                    'branchLines' => [],
                ];
                $items[$key]['totalQuantity'] += $qty;
                $items[$key]['currentUnitPrice'] = max($items[$key]['currentUnitPrice'], (float) $line->unit_price);
                $items[$key]['orderIds'][$order->id] = true;

                $branchId = $order->branch_id ?? 'unknown';
                $items[$key]['branchLines'][$branchId] ??= [
                    'branchId' => $order->branch_id,
                    'branchName' => $order->branch?->name ?? '—',
                    'qty' => 0.0,
                    'unit' => $line->unit_of_measurement,
                ];
                $items[$key]['branchLines'][$branchId]['qty'] += $qty;
            }
        }

        $suggestions = $this->suggestionsFor(collect($items)->pluck('itemId')->filter()->values()->all());

        return collect($items)->map(function (array $row) use ($suggestions) {
            $suggestion = $row['itemId'] !== null ? ($suggestions[$row['itemId']] ?? null) : null;
            $current = (float) $row['currentUnitPrice'];
            $totalQty = (float) $row['totalQuantity'];
            $unitPrice = $suggestion['unitPrice'] ?? $current;

            $savings = null;
            $savingsPct = null;
            if ($suggestion !== null && $current > 0) {
                $calc = $this->calc->calculateSavings($current, (float) $suggestion['unitPrice']);
                $savings = round($calc['savings_amount'] * $totalQty, 2);
                $savingsPct = $calc['savings_percentage'];
            }

            return [
                'itemId' => $row['itemId'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'requestsCount' => count($row['orderIds']),
                'branchesCount' => count($row['branchLines']),
                'branchLines' => array_map(fn ($b) => $b + ['qty' => round($b['qty'], 3)], array_values($row['branchLines'])),
                'totalQuantity' => round($totalQty, 3),
                'suggestedSupplier' => $suggestion,
                'unitPrice' => round((float) $unitPrice, 2),
                'totalCost' => round((float) $unitPrice * $totalQty, 2),
                'savings' => $savings,
                'savingsPct' => $savingsPct,
                'status' => 'new',
                'orderIds' => array_keys($row['orderIds']),
            ];
        })->values()->all();
    }

    /**
     * Send a supplier's consolidation batch: stamp a group over the orders
     * (assigning the supplier to officer orders that have none yet). The
     * item-level savings are snapshotted (T11.8) and an ETA is stored — the
     * caller-supplied date, else the earliest member-item next-supply date.
     *
     * @param  string[]|null  $orderIds  explicit subset; null = all consolidatable for the supplier
     * @param  string[]|null  $branchIds  tenant scope
     */
    public function send(string $supplierId, ?array $orderIds, string $asabUserId, ?array $branchIds, ?string $expectedDeliveryDate = null): PurchaseOrderGroup
    {
        Supplier::findOrFail($supplierId);

        return DB::transaction(function () use ($supplierId, $orderIds, $asabUserId, $branchIds, $expectedDeliveryDate) {
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

            $savings = $this->computeGroupSavings($orders, $supplierId);

            $group = PurchaseOrderGroup::create([
                'supplier_id' => $supplierId,
                'created_by_asab_user_id' => $asabUserId,
                'sent_at' => now(),
                'savings_amount' => $savings['amount'],
                'savings_pct' => $savings['pct'],
                'expected_delivery_date' => $expectedDeliveryDate ?? $this->deriveEta($orders),
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
                'statusLabel' => PurchaseOrderGroup::STATUS_LABELS[$g->deriveStatus()] ?? $g->deriveStatus(),
                'savings' => $g->savings_amount !== null ? (float) $g->savings_amount : null,
                'savingsPct' => $g->savings_pct !== null ? (float) $g->savings_pct : null,
                'eta' => optional($g->expected_delivery_date)->toDateString(),
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
            'statusLabel' => PurchaseOrderGroup::STATUS_LABELS[$group->deriveStatus()] ?? $group->deriveStatus(),
            'savings' => $group->savings_amount !== null ? (float) $group->savings_amount : null,
            'savingsPct' => $group->savings_pct !== null ? (float) $group->savings_pct : null,
            'eta' => optional($group->expected_delivery_date)->toDateString(),
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

    /**
     * Cheapest active supplier offer per catalog item (the "suggested supplier").
     *
     * @param  string[]  $itemIds
     * @return array<string, array{id:string, name:string, unitPrice:float}>
     */
    private function suggestionsFor(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $products = SupplierProduct::whereIn('item_id', $itemIds)
            ->where('is_available', true)
            ->get(['supplier_id', 'item_id', 'unit_price']);

        $names = Supplier::whereIn('id', $products->pluck('supplier_id')->unique())->pluck('name', 'id');

        return $products->groupBy('item_id')->map(function (Collection $rows) use ($names) {
            $cheapest = $rows->sortBy('unit_price')->first();

            return [
                'id' => $cheapest->supplier_id,
                'name' => $names[$cheapest->supplier_id] ?? '—',
                'unitPrice' => (float) $cheapest->unit_price,
            ];
        })->all();
    }

    /**
     * Savings a batch captures vs the entered line prices: for every counted
     * item, (entered unit price − cheapest supplier price) × total quantity.
     * Items with no supplier offer contribute nothing (never a crash).
     *
     * @param  Collection<int, PurchaseOrder>  $orders
     * @return array{amount:float, pct:float}
     */
    private function computeGroupSavings(Collection $orders, ?string $supplierId): array
    {
        $items = $this->aggregateItems($orders, $supplierId);
        $suggestions = $this->suggestionsFor(collect($items)->pluck('itemId')->filter()->values()->all());

        $savings = 0.0;
        $baseline = 0.0;
        foreach ($items as $row) {
            $suggestion = $row['itemId'] !== null ? ($suggestions[$row['itemId']] ?? null) : null;
            $current = (float) $row['unitPrice'];
            if ($suggestion === null || $current <= 0) {
                continue;
            }
            $qty = (float) $row['totalQuantity'];
            $calc = $this->calc->calculateSavings($current, (float) $suggestion['unitPrice']);
            $savings += $calc['savings_amount'] * $qty;
            $baseline += $current * $qty;
        }

        return [
            'amount' => round($savings, 2),
            'pct' => $baseline > 0 ? round($savings / $baseline * 100, 2) : 0.0,
        ];
    }

    /**
     * ETA fallback for a batch: the earliest next-supply date declared on any
     * member order line, or null when none is set.
     *
     * @param  Collection<int, PurchaseOrder>  $orders
     */
    private function deriveEta(Collection $orders): ?string
    {
        $dates = $orders->flatMap->items->pluck('next_supply_date')->filter();

        return $dates->isEmpty() ? null : $dates->min()->toDateString();
    }

    /**
     * PRC-1.1 headline KPIs over the REAL (bridge) pipeline — counts the
     * dashboard overview must show alongside the Operations-family figures,
     * plus this-month savings vs last month.
     *
     * @param  string[]|null  $branchIds  tenant scope
     * @return array{incoming:int, readyToSend:int, sentAwaitingConfirmation:int, monthlySavings:array}
     */
    public function procurementKpis(?array $branchIds): array
    {
        $bound = fn (Builder $q) => $q->whereIn('order_type', self::SUPPLIER_BOUND)
            ->when($branchIds !== null, fn ($w) => $w->whereIn('branch_id', $branchIds));

        $incoming = $bound(PurchaseOrder::query())->whereIn('status', self::INCOMING_STATUSES)->count();
        $readyToSend = $this->consolidatable($branchIds)->count();
        $sentAwaiting = $bound(PurchaseOrder::query())
            ->whereNotNull('group_id')->where('status', OrderStatus::CONFIRMED)->count();

        $monthStart = now()->startOfMonth();
        $prevStart = (clone $monthStart)->subMonthNoOverflow();

        $groupSavings = fn ($from, $to) => (float) PurchaseOrderGroup::query()
            ->when($branchIds !== null, fn ($q) => $q->whereHas('orders', fn ($o) => $o->whereIn('branch_id', $branchIds)))
            ->whereBetween('sent_at', [$from, $to])
            ->sum('savings_amount');

        $current = $groupSavings($monthStart, now());
        $previous = $groupSavings($prevStart, $monthStart);

        $purchases = (float) $bound(PurchaseOrder::query())
            ->whereHas('group', fn ($g) => $g->whereBetween('sent_at', [$monthStart, now()]))
            ->sum('total_amount');

        return [
            'incoming' => $incoming,
            'readyToSend' => $readyToSend,
            'sentAwaitingConfirmation' => $sentAwaiting,
            'monthlySavings' => [
                'amount' => round($current, 2),
                'pctOfPurchases' => $purchases > 0 ? round($current / $purchases * 100, 2) : 0.0,
                'trendPct' => $previous > 0
                    ? round(($current - $previous) / $previous * 100, 2)
                    : ($current > 0 ? 100.0 : 0.0),
            ],
        ];
    }
}
