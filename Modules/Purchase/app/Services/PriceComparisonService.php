<?php

namespace Modules\Purchase\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Enums\InventorySessionStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Purchase\Constants\PurchaseConstants;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PriceHistory;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\SavedPriceComparison;
use Modules\Purchase\Models\SupplierItem;
use Modules\Purchase\Traits\ItemHelperTrait;
use Modules\Purchase\Transformers\SupplierResource;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierProduct;

class PriceComparisonService implements \Modules\Purchase\Services\Contracts\PriceComparisonServiceInterface
{
    use ItemHelperTrait;

    /**
     * Compare prices for an item across all sources
     *
     * Returns detailed comparison including:
     * - Product Name
     * - Quantity (optional, defaults to 1)
     * - Period: Last 3 months price trends
     * - Comparison Chart: Monthly price variation
     * - Comparison Table: Price, Delivery Days, Rating for each Order Type
     * - Benefits Analysis: Best option, compliance, fastest delivery, lowest price
     */
    public function comparePrices(string $itemId, ?float $quantity = null, ?string $excludeBranchId = null): array
    {
        // Use default quantity from constants
        $quantity = $quantity ?? PurchaseConstants::DEFAULT_QUANTITY;

        // Get item details - itemId can be either Item.id or BranchItem.id
        // Try to find Item directly first, then try BranchItem if not found
        $item = null;
        $branchItem = null;
        $itemPrice = null;
        $actualItemId = $itemId;

        // Try to find Item directly (itemId is Item.id)
        $item = Item::select('id', 'name', 'code', 'unit', 'logo')->find($itemId);

        // If Item not found, try to find BranchItem (itemId might be BranchItem.id)
        if (! $item) {
            $branchItem = BranchItem::with('item:id,name,code,unit,logo')->find($itemId);

            if ($branchItem && $branchItem->item) {
                $item = $branchItem->item;
                $itemPrice = $branchItem->price ? (float) $branchItem->price : null;
                $actualItemId = $item->id;
            }
        } else {
            // Item found, try to get BranchItem for price if we have branch context
            if ($excludeBranchId) {
                $branchItem = BranchItem::where('branch_id', $excludeBranchId)
                    ->where('item_id', $item->id)
                    ->first();

                if ($branchItem) {
                    $itemPrice = $branchItem->price ? (float) $branchItem->price : null;
                }
            }
        }

        // Handle item_logo using helper method
        $itemLogo = $this->getItemLogoUrl($item?->logo);

        $comparison = [
            'item_id' => $actualItemId,
            'item_name' => $item ? $item->name : null,
            'item_code' => $item ? $item->code : null,
            'item_unit' => $item ? $item->unit : null,
            'item_logo' => $itemLogo,
            'item_price' => $itemPrice, // Get price from BranchItem if available
            'quantity' => $quantity,
            'period' => 'Last 3 Months',
            'sources' => [],
            'comparison_table' => [],
            'best_option' => null,
            'insights' => [],
            'price_trends' => [],
            'price_change' => null,
        ];

        // Direct Supplier prices (with actual order history from all branches)
        // Use actual Item.id for comparison (resolve from BranchItem if needed)
        $supplierPrices = $this->getSupplierPrices($actualItemId, $quantity);
        if ($supplierPrices->isNotEmpty()) {
            $comparison['sources']['direct_supplier'] = $supplierPrices->toArray();
        } else {
            // Fallback: Get prices from actual orders if no SupplierItem records exist
            $supplierPricesFromOrders = $this->getSupplierPricesFromOrders($actualItemId, $quantity);
            if ($supplierPricesFromOrders->isNotEmpty()) {
                $comparison['sources']['direct_supplier'] = $supplierPricesFromOrders->toArray();
            }
            // If still empty, don't add direct_supplier to sources
        }

        // Via Purchasing Officer (average/estimated prices from actual orders from all branches)
        // Use actual Item.id for comparison
        $poPrices = $this->getPurchasingOfficerPrices($actualItemId);
        if ($poPrices) {
            $comparison['sources']['via_purchasing_officer'] = $poPrices;
        }

        // Internal Transfer options (from all branches except current)
        // Use actual Item.id for comparison
        $transferOptions = $this->getInternalTransferOptions($actualItemId, $quantity, $excludeBranchId);
        if ($transferOptions->isNotEmpty()) {
            $comparison['sources']['internal_transfer'] = $transferOptions->toArray();
        }

        // Get price trends from actual purchase orders (from all branches)
        // Use actual Item.id for comparison
        $comparison['price_trends'] = $this->getPriceTrends($actualItemId);

        // Calculate price change percentage
        $comparison['price_change'] = $this->calculatePriceChange($comparison['price_trends']);

        // Build comparison table
        $comparison['comparison_table'] = $this->buildComparisonTable($comparison['sources'], $quantity);

        // Calculate best options and insights
        $comparison['insights'] = $this->calculateInsights($comparison['sources']);
        $comparison['best_option'] = $this->determineBestOption($comparison['sources']);

        return $comparison;
    }

    /**
     * Get supplier prices for an item
     * Includes actual order history for delivery days and rating calculation
     */
    private function getSupplierPrices(string $itemId, float $quantity): Collection
    {
        // Performance: Eager load only needed supplier columns
        $supplierItems = SupplierItem::with(['supplier:id,name,status,rating'])
            ->byItem($itemId)
            ->available()
            ->whereHas('supplier', fn ($q) => $q->active())
            ->get()
            ->filter(fn ($item) => $item->isWithinQuantityLimits($quantity));

        // Performance: Use optimized query with indexes for order history
        $threeMonthsAgo = now()->subMonths(PurchaseConstants::PRICE_HISTORY_MONTHS);

        // Use DB query for better performance instead of Eloquent for aggregation
        $orderHistoryData = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_order_items.item_id', $itemId)
            ->where('purchase_orders.order_type', OrderType::DIRECT_SUPPLIER->value)
            ->where('purchase_orders.created_at', '>=', $threeMonthsAgo)
            ->whereIn('purchase_orders.status', [
                OrderStatus::CONFIRMED->value,
                OrderStatus::PARTIAL_CONFIRMATION->value,
                OrderStatus::CLOSED->value,
                OrderStatus::DELIVERED->value,
            ])
            ->select([
                'purchase_orders.supplier_id',
                'purchase_orders.created_at',
                'purchase_orders.confirmed_at',
                'purchase_orders.received_at',
                'purchase_orders.closed_at',
            ])
            ->get()
            ->groupBy('supplier_id');

        // Enhance supplier items with actual order data
        return $supplierItems->map(function ($supplierItem) use ($orderHistoryData, $quantity) {
            $supplierId = $supplierItem->supplier_id;
            $history = $orderHistoryData->get($supplierId, collect());

            // Calculate average delivery days from actual orders
            $avgDeliveryDays = null;
            if ($history->isNotEmpty()) {
                $deliveryDays = $history->map(function ($order) {
                    $createdAt = \Carbon\Carbon::parse($order->created_at);
                    $completedAt = $order->confirmed_at
                        ? \Carbon\Carbon::parse($order->confirmed_at)
                        : ($order->received_at
                            ? \Carbon\Carbon::parse($order->received_at)
                            : ($order->closed_at
                                ? \Carbon\Carbon::parse($order->closed_at)
                                : now()));

                    return $createdAt->diffInDays($completedAt);
                })->avg();
                $avgDeliveryDays = round($deliveryDays, 1);
            }

            // Get rating from supplier or use default
            $rating = $supplierItem->rating
                ?? $supplierItem->supplier->rating
                ?? PurchaseConstants::DEFAULT_SUPPLIER_RATING;

            $deliveryHours = $supplierItem->delivery_hours ?? PurchaseConstants::DEFAULT_DELIVERY_HOURS;

            return [
                'supplier_id' => $supplierId,
                'supplier_name' => $supplierItem->supplier->name,
                'supplier_status' => $supplierItem->supplier->status->value,
                'unit_price' => $supplierItem->unit_price,
                'total_price' => $supplierItem->unit_price * $quantity,
                'delivery_hours' => $deliveryHours,
                'delivery_days' => $avgDeliveryDays ?? ceil($deliveryHours / PurchaseConstants::HOURS_PER_DAY),
                'rating' => round($rating, 1),
                'is_available' => $supplierItem->is_available,
                'order_count' => $history->count(),
            ];
        });
    }

    /**
     * Get supplier prices from actual orders when SupplierItem records don't exist
     * This is a fallback method to show prices from real order history
     */
    private function getSupplierPricesFromOrders(string $itemId, float $quantity): Collection
    {
        // Performance: Use optimized DB query instead of Eloquent for better performance
        $threeMonthsAgo = now()->subMonths(PurchaseConstants::PRICE_HISTORY_MONTHS);

        $supplierData = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->join('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
            ->where('purchase_order_items.item_id', $itemId)
            ->where('purchase_orders.order_type', OrderType::DIRECT_SUPPLIER->value)
            ->where('purchase_orders.created_at', '>=', $threeMonthsAgo)
            ->whereIn('purchase_orders.status', [
                OrderStatus::CONFIRMED->value,
                OrderStatus::PARTIAL_CONFIRMATION->value,
                OrderStatus::CLOSED->value,
                OrderStatus::DELIVERED->value,
            ])
            ->select([
                'purchase_orders.supplier_id',
                'suppliers.name as supplier_name',
                'suppliers.status as supplier_status',
                'suppliers.rating',
                'suppliers.default_delivery_hours',
                'purchase_order_items.unit_price',
                'purchase_orders.created_at',
                'purchase_orders.confirmed_at',
                'purchase_orders.received_at',
                'purchase_orders.closed_at',
            ])
            ->get()
            ->groupBy('supplier_id')
            ->map(function ($items, $supplierId) use ($quantity) {
                $firstItem = $items->first();

                if (! $firstItem) {
                    return null;
                }

                // Calculate average unit price
                $avgUnitPrice = $items->avg('unit_price');

                // Calculate average delivery days
                $deliveryDays = $items->map(function ($item) {
                    $createdAt = \Carbon\Carbon::parse($item->created_at);
                    $completedAt = $item->confirmed_at
                        ? \Carbon\Carbon::parse($item->confirmed_at)
                        : ($item->received_at
                            ? \Carbon\Carbon::parse($item->received_at)
                            : ($item->closed_at
                                ? \Carbon\Carbon::parse($item->closed_at)
                                : now()));

                    return $createdAt->diffInDays($completedAt);
                })->avg();

                // Get rating from supplier or use default
                $rating = $firstItem->rating ?? PurchaseConstants::DEFAULT_SUPPLIER_RATING;
                $deliveryHours = $firstItem->default_delivery_hours ?? PurchaseConstants::DEFAULT_DELIVERY_HOURS;

                return [
                    'supplier_id' => $supplierId,
                    'supplier_name' => $firstItem->supplier_name,
                    'supplier_status' => $firstItem->supplier_status ?? 'online',
                    'unit_price' => round($avgUnitPrice, 2),
                    'total_price' => round($avgUnitPrice * $quantity, 2),
                    'delivery_hours' => $deliveryHours,
                    'delivery_days' => round($deliveryDays ?? ($deliveryHours / PurchaseConstants::HOURS_PER_DAY), 1),
                    'rating' => round($rating, 1),
                    'is_available' => true, // Assume available if we have orders
                    'order_count' => $items->count(),
                ];
            })
            ->filter()
            ->values();

        return $supplierData;
    }

    /**
     * Get purchasing officer prices from actual purchase orders
     */
    public function getPurchasingOfficerPrices(string $itemId): ?array
    {
        $threeMonthsAgo = now()->subMonths(3);

        // Get actual orders from last 3 months (from all branches)
        $orderItems = PurchaseOrderItem::with(['purchaseOrder'])
            ->where('item_id', $itemId)
            ->whereHas('purchaseOrder', function ($query) use ($threeMonthsAgo) {
                $query->where('order_type', OrderType::VIA_PURCHASING_OFFICER)
                    ->where('created_at', '>=', $threeMonthsAgo)
                    ->whereIn('status', [
                        OrderStatus::CONFIRMED,
                        OrderStatus::PARTIAL_CONFIRMATION,
                        OrderStatus::CLOSED,
                        OrderStatus::DELIVERED,
                    ]);
            })
            ->get();

        if ($orderItems->isEmpty()) {
            // Fallback to PriceHistory if no actual orders found
            $history = PriceHistory::byItem($itemId)
                ->bySourceType(OrderType::VIA_PURCHASING_OFFICER)
                ->lastThreeMonths()
                ->orderBy('recorded_date', 'desc')
                ->first();

            if (! $history) {
                return null;
            }

            return [
                'unit_price' => $history->unit_price,
                'delivery_days' => $history->delivery_days ?? 4,
                'rating' => $history->rating,
                'processing_times' => [
                    'standard' => ['min_days' => 3, 'max_days' => 5],
                    'urgent' => ['min_days' => 1, 'max_days' => 2],
                ],
            ];
        }

        // Calculate average price from actual orders
        $avgPrice = $orderItems->avg('unit_price');

        // Calculate average delivery days (from created_at to confirmed_at or received_at)
        $deliveryDays = $orderItems->map(function ($item) {
            $order = $item->purchaseOrder;
            $createdAt = $order->created_at;
            $completedAt = $order->confirmed_at ?? $order->received_at ?? $order->closed_at ?? now();

            return $createdAt->diffInDays($completedAt);
        })->avg();

        // Get rating from supplier if available, or use default
        $rating = $orderItems->first()?->purchaseOrder?->supplier?->rating ?? 4.5;

        return [
            'unit_price' => round($avgPrice, 2),
            'delivery_days' => round($deliveryDays ?? 4, 1),
            'rating' => round($rating, 1),
            'processing_times' => [
                'standard' => ['min_days' => 3, 'max_days' => 5],
                'urgent' => ['min_days' => 1, 'max_days' => 2],
            ],
            'order_count' => $orderItems->count(),
        ];
    }

    /**
     * Get internal transfer options from all branches
     * Uses actual prices from previous orders if available
     */
    public function getInternalTransferOptions(string $itemId, float $quantity, ?string $excludeBranchId = null): Collection
    {
        // Performance: Use optimized DB query for average price calculation
        $threeMonthsAgo = now()->subMonths(PurchaseConstants::PRICE_HISTORY_MONTHS);

        $avgUnitPrice = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_order_items.item_id', $itemId)
            ->where('purchase_orders.order_type', OrderType::INTERNAL_TRANSFER->value)
            ->where('purchase_orders.created_at', '>=', $threeMonthsAgo)
            ->whereIn('purchase_orders.status', [
                OrderStatus::CONFIRMED->value,
                OrderStatus::PARTIAL_CONFIRMATION->value,
                OrderStatus::CLOSED->value,
                OrderStatus::DELIVERED->value,
            ])
            ->avg('purchase_order_items.unit_price');

        $avgUnitPrice = $avgUnitPrice ? round((float) $avgUnitPrice, 2) : 0;

        // Performance: Eager load only needed branch columns
        $minAvailability = $quantity * (PurchaseConstants::MIN_AVAILABILITY_PERCENTAGE / 100);

        return BranchInventory::with(['branch:id,name,image'])
            ->byItem($itemId)
            ->available()
            ->when($excludeBranchId, fn ($q) => $q->where('branch_id', '!=', $excludeBranchId))
            ->whereRaw('(available_quantity - reserved_quantity) >= ?', [$minAvailability])
            ->get()
            ->map(function ($inventory) use ($quantity, $avgUnitPrice) {
                $availableQty = (float) $inventory->actual_available;

                return [
                    'branch_id' => $inventory->branch_id,
                    'branch_name' => $inventory->branch->name ?? null,
                    'branch_image' => $inventory->branch->image ?? null,
                    'available_quantity' => $availableQty,
                    'availability_percentage' => min(
                        PurchaseConstants::FULL_AVAILABILITY_PERCENTAGE,
                        ($availableQty / $quantity) * 100
                    ),
                    'quality' => $inventory->quality?->value,
                    'expiry_date' => $inventory->earliest_expiry_date?->format('Y-m-d'),
                    'cooling_status' => $inventory->cooling_status,
                    'last_update' => $inventory->last_inventory_update?->diffForHumans(),
                    'unit_price' => $avgUnitPrice,
                    'total_price' => $avgUnitPrice * $quantity,
                    'delivery_days' => PurchaseConstants::DEFAULT_INTERNAL_TRANSFER_DAYS, // Internal transfers usually take 1-2 days
                    'rating' => null, // Would come from branch manager stats
                    'response_rate' => null,
                    'distance' => null, // Would be calculated from coordinates
                ];
            });
    }

    /**
     * Get price trends for last 3 months from actual purchase orders
     * Returns actual prices that were paid for this item in the last 3 months (from all branches)
     *
     * Returns array of objects with format:
     * [
     *   { "date": "2025-04-01", "value": 10 },
     *   { "date": "2025-04-03", "value": 14 }
     * ]
     *
     * Where:
     * - date: The date when the purchase was made (from purchase_order.created_at)
     * - value: The unit_price that was paid for the item (from purchase_order_item.unit_price)
     */
    public function getPriceTrends(string $itemId): array
    {
        // Performance: Use optimized DB query instead of loading all data
        $trends = collect();

        for ($i = 0; $i <= 2; $i++) {
            $month = now()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            // Get latest purchase in this month using optimized query
            $latestItem = DB::table('purchase_order_items')
                ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
                ->where('purchase_order_items.item_id', $itemId)
                ->where('purchase_orders.created_at', '>=', $monthStart)
                ->where('purchase_orders.created_at', '<=', $monthEnd)
                ->whereIn('purchase_orders.status', [
                    OrderStatus::CONFIRMED->value,
                    OrderStatus::PARTIAL_CONFIRMATION->value,
                    OrderStatus::CLOSED->value,
                    OrderStatus::DELIVERED->value,
                ])
                ->select([
                    'purchase_order_items.unit_price',
                    'purchase_orders.created_at',
                ])
                ->orderBy('purchase_orders.created_at', 'desc')
                ->first();

            if ($latestItem) {
                $purchaseDate = \Carbon\Carbon::parse($latestItem->created_at);
                $trends->push([
                    'date' => $purchaseDate->format('Y-m-d'),
                    'value' => (float) $latestItem->unit_price,
                ]);
            }
        }

        // Return trends sorted from oldest to newest
        return $trends->sortBy('date')->values()->toArray();
    }

    /**
     * Build comparison table with all order types
     */
    private function buildComparisonTable(array $sources, float $quantity): array
    {
        $table = [];

        // Direct Supplier - get best option (lowest price or highest rating)
        if (! empty($sources['direct_supplier']) && is_array($sources['direct_supplier'])) {
            $suppliers = collect($sources['direct_supplier'])
                ->filter(fn ($s) => isset($s['unit_price']) && $s['unit_price'] !== null);

            if ($suppliers->isNotEmpty()) {
                $bestSupplier = $suppliers->sortBy('unit_price')->first();

                $table[] = [
                    'order_type' => 'direct_supplier',
                    'order_type_label' => OrderType::DIRECT_SUPPLIER->label(),
                    'price' => $bestSupplier['unit_price'],
                    'total_price' => $bestSupplier['unit_price'] * $quantity,
                    'delivery_days' => $bestSupplier['delivery_days'] ?? null,
                    'rating' => $bestSupplier['rating'] ?? null,
                    'supplier_name' => $bestSupplier['supplier_name'] ?? null,
                ];
            }
        }

        // Via Purchasing Officer
        if (! empty($sources['via_purchasing_officer'])) {
            $po = $sources['via_purchasing_officer'];
            $table[] = [
                'order_type' => 'via_purchasing_officer',
                'order_type_label' => OrderType::VIA_PURCHASING_OFFICER->label(),
                'price' => $po['unit_price'],
                'total_price' => $po['unit_price'] * $quantity,
                'delivery_days' => $po['delivery_days'],
                'rating' => $po['rating'],
                'supplier_name' => null,
            ];
        }

        // Internal Transfer
        if (! empty($sources['internal_transfer'])) {
            $transfer = collect($sources['internal_transfer'])->first();
            $table[] = [
                'order_type' => 'internal_transfer',
                'order_type_label' => OrderType::INTERNAL_TRANSFER->label(),
                'price' => 0, // Free transfer
                'total_price' => 0,
                'delivery_days' => 1, // Usually fastest
                'rating' => null,
                'supplier_name' => $transfer['branch_name'] ?? null,
            ];
        }

        return $table;
    }

    /**
     * Calculate price change percentage from trends
     *
     * Now handles the new format: array of {date, value} objects
     */
    private function calculatePriceChange(array $trends): ?array
    {
        if (empty($trends)) {
            return null;
        }

        // Check if it's the new format (array of {date, value} objects)
        $isNewFormat = isset($trends[0]) && is_array($trends[0]) && isset($trends[0]['date']) && isset($trends[0]['value']);

        if (! $isNewFormat) {
            // Old format - return null for now (can be removed later)
            return null;
        }

        if (count($trends) < 2) {
            return null;
        }

        // Get first and last prices from the trends
        $firstPrice = $trends[0]['value'] ?? null;
        $lastPrice = $trends[count($trends) - 1]['value'] ?? null;

        if ($firstPrice === null || $lastPrice === null || $firstPrice == 0) {
            return null;
        }

        $change = (($lastPrice - $firstPrice) / $firstPrice) * 100;
        $isIncrease = $change > 0;

        return [
            'percentage' => round(abs($change), 1),
            'is_increase' => $isIncrease,
            'label' => $isIncrease
                ? '+'.round($change, 1).'% Price Increase'
                : round($change, 1).'% Price Decrease',
            'status' => $isIncrease ? 'HIGHER THAN FIRST PURCHASE' : 'LOWER THAN FIRST PURCHASE',
        ];
    }

    /**
     * Calculate insights from comparison
     */
    private function calculateInsights(array $sources): array
    {
        $insights = [
            'lowest_price' => null,
            'fastest_delivery' => null,
            'best_rating' => null,
            'best_compliance' => null,
        ];

        $allOptions = [];

        // Collect all options
        if (! empty($sources['direct_supplier'])) {
            foreach ($sources['direct_supplier'] as $option) {
                $allOptions[] = array_merge($option, ['type' => 'direct_supplier']);
            }
        }

        if (! empty($sources['via_purchasing_officer'])) {
            $allOptions[] = array_merge($sources['via_purchasing_officer'], ['type' => 'via_purchasing_officer']);
        }

        if (! empty($sources['internal_transfer'])) {
            foreach ($sources['internal_transfer'] as $option) {
                $allOptions[] = array_merge($option, ['type' => 'internal_transfer']);
            }
        }

        if (empty($allOptions)) {
            return $insights;
        }

        // Find lowest price
        $lowestPrice = collect($allOptions)->sortBy('unit_price')->first();
        $insights['lowest_price'] = array_merge(
            $this->mapSourceMeta($lowestPrice),
            [
                'value' => $lowestPrice['unit_price'],
                'label' => OrderType::from($lowestPrice['type'])->label(),
            ]
        );

        // Find fastest delivery
        $fastest = collect($allOptions)
            ->filter(fn ($o) => isset($o['delivery_days']))
            ->sortBy('delivery_days')
            ->first();

        if ($fastest) {
            $insights['fastest_delivery'] = array_merge(
                $this->mapSourceMeta($fastest),
                [
                    'value' => $fastest['delivery_days'],
                    'label' => OrderType::from($fastest['type'])->label(),
                ]
            );
        }

        // Find best rating (also used for compliance)
        $bestRated = collect($allOptions)
            ->filter(fn ($o) => isset($o['rating']))
            ->sortByDesc('rating')
            ->first();

        if ($bestRated) {
            $bestRating = array_merge(
                $this->mapSourceMeta($bestRated),
                [
                    'value' => $bestRated['rating'],
                    'label' => OrderType::from($bestRated['type'])->label(),
                ]
            );
            $insights['best_rating'] = $bestRating;
            $insights['best_compliance'] = $bestRating;
        }

        return $insights;
    }

    /**
     * Determine best overall option
     */
    private function determineBestOption(array $sources): ?array
    {
        $allOptions = [];

        if (! empty($sources['direct_supplier'])) {
            foreach ($sources['direct_supplier'] as $option) {
                $allOptions[] = array_merge($option, ['type' => 'direct_supplier']);
            }
        }

        if (! empty($sources['via_purchasing_officer'])) {
            $allOptions[] = array_merge($sources['via_purchasing_officer'], ['type' => 'via_purchasing_officer']);
        }

        if (! empty($sources['internal_transfer'])) {
            foreach ($sources['internal_transfer'] as $option) {
                $allOptions[] = array_merge($option, ['type' => 'internal_transfer']);
            }
        }

        if (empty($allOptions)) {
            return null;
        }

        // Score each option (lower is better for price/delivery, higher is better for rating)
        // Filter out options without essential data (delivery_days or rating)
        $scored = collect($allOptions)
            ->filter(function ($option) {
                // For internal_transfer, delivery_days is required
                if ($option['type'] === 'internal_transfer') {
                    return isset($option['delivery_days']);
                }

                // For other types, at least one of delivery_days or rating should exist
                return isset($option['delivery_days']) || isset($option['rating']);
            })
            ->map(function ($option) {
                $priceScore = $option['unit_price'] ?? 0;
                $deliveryScore = ($option['delivery_days'] ?? PurchaseConstants::DEFAULT_DELIVERY_DAYS) * 10;
                $ratingScore = 100 - (($option['rating'] ?? PurchaseConstants::DEFAULT_RATING) * 20);

                return array_merge($option, [
                    'composite_score' => ($priceScore * 0.4) + ($deliveryScore * 0.3) + ($ratingScore * 0.3),
                ]);
            });

        if ($scored->isEmpty()) {
            return null;
        }

        $best = $scored->sortBy('composite_score')->first();

        return array_merge(
            $this->mapSourceMeta($best),
            [
                'type_label' => OrderType::from($best['type'])->label(),
                'reason' => 'Best combination of price, delivery time, and rating',
            ]
        );
    }

    /**
     * Map source meta (type, id, name) for insights and recommendations.
     */
    private function mapSourceMeta(array $option): array
    {
        $type = $option['type'] ?? null;

        return [
            'type' => $type,
            'source_type' => $type,
            'source_id' => match ($type) {
                'direct_supplier' => $option['supplier_id'] ?? null,
                'internal_transfer' => $option['branch_id'] ?? null,
                'via_purchasing_officer' => null,
                default => null,
            },
            'source_name' => match ($type) {
                'direct_supplier' => $option['supplier_name'] ?? null,
                'internal_transfer' => $option['branch_name'] ?? null,
                'via_purchasing_officer' => 'Purchasing Officer',
                default => null,
            },
        ];
    }

    /**
     * Get suppliers for an item, or every orderable supplier when no item is
     * given.
     *
     * `item_id` is optional on the route, but this method used to type-hint it
     * as `string`: the app's «All Suppliers» screen, which sends no item, hit a
     * TypeError that surfaced as a 500 and rendered as an empty list under a
     * header still counting the suppliers it expected to see.
     */
    public function getSuppliers(?string $itemId, array $filters = [], ?string $branchId = null): Collection
    {
        if ($itemId === null || $itemId === '') {
            return $this->allOrderableSuppliers($filters, $branchId);
        }

        $query = SupplierItem::with('supplier')
            ->byItem($itemId)
            ->available()
            ->whereHas('supplier', fn ($q) => $q->active());

        // Filter by supplier status (Supplier model has no scopeByStatus; filter by column)
        if (! empty($filters['status'])) {
            $status = $filters['status'];
            if ($status instanceof \Modules\Purchase\Enums\SupplierStatus) {
                $status = $status->value;
            }
            $query->whereHas('supplier', fn ($q) => $q->where('status', $status));
        }

        // Filter by delivery time
        if (! empty($filters['max_delivery_hours'])) {
            $query->byDeliveryTime($filters['max_delivery_hours']);
        }

        // Search by supplier name (Supplier model has no scopeSearch; use where like)
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->whereHas('supplier', fn ($q) => $q->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            }));
        }

        $fromSupplierItems = $query->get()->map(fn ($item) => $this->supplierOptionRow($item));

        // The supplier's own mobile inventory lives in supplier_products —
        // getDirectSupplierItems already merges both tables, but this list
        // read supplier_items only, so a supplier selling the item through
        // their app never appeared here (meeting 2026-07-30). Union them,
        // supplier_products winning on conflict.
        $fromSupplierProducts = SupplierProduct::with('supplier')
            ->where('item_id', $itemId)
            ->available()
            ->whereHas('supplier', fn ($q) => $q->active())
            ->when(! empty($filters['max_delivery_hours']), fn ($q) => $q->where('delivery_hours', '<=', $filters['max_delivery_hours']))
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $term = $filters['search'];
                $q->whereHas('supplier', fn ($s) => $s->where(function ($s) use ($term) {
                    $s->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                }));
            })
            ->get()
            ->map(fn ($p) => $this->supplierOptionRow($p));

        return $fromSupplierProducts
            ->concat($fromSupplierItems)
            ->unique('supplier_id')
            ->values();
    }

    /**
     * «All Suppliers»: every supplier the branch may order from, with its
     * cheapest catalog row attached when it has one. Brand-scoped and
     * fail-closed exactly like GET /purchase/suppliers (meeting 2026-07-30) —
     * an unlinked branch sees nothing rather than every tenant's suppliers.
     */
    private function allOrderableSuppliers(array $filters, ?string $branchId): Collection
    {
        $orderableIds = $branchId !== null
            ? app(\Modules\Expense\Services\SupplierBrandScopeService::class)->orderableSupplierIds($branchId)
            : null;

        if ($orderableIds === []) {
            return collect();
        }

        $suppliers = Supplier::query()
            ->active()
            ->when($orderableIds !== null, fn ($q) => $q->whereIn('id', $orderableIds))
            ->when(! empty($filters['status']), function ($q) use ($filters) {
                $status = $filters['status'];

                $q->byStatus($status instanceof \Modules\Purchase\Enums\SupplierStatus ? $status->value : $status);
            })
            ->when(! empty($filters['max_delivery_hours']), fn ($q) => $q->byDeliveryTime((int) $filters['max_delivery_hours']))
            ->when(! empty($filters['search']), fn ($q) => $q->search($filters['search']))
            ->orderBy('name')
            // Bounded read: the picker is a list, not an export.
            ->limit(PurchaseConstants::MAX_PER_PAGE)
            ->get();

        if ($suppliers->isEmpty()) {
            return collect();
        }

        // Cheapest available row per supplier, both catalogs, one query each —
        // no N+1 over the supplier list.
        $supplierIds = $suppliers->modelKeys();

        $cheapest = SupplierItem::whereIn('supplier_id', $supplierIds)
            ->available()
            ->get()
            ->concat(
                SupplierProduct::whereIn('supplier_id', $supplierIds)->available()->get()
            )
            ->sortBy(fn ($row) => (float) ($row->unit_price ?? 0))
            ->groupBy('supplier_id')
            // keyBy() would keep the LAST row per supplier — the dearest.
            ->map(fn ($rows) => $rows->first());

        return $suppliers->map(function ($supplier) use ($cheapest) {
            $row = $cheapest->get($supplier->getKey());

            if ($row !== null) {
                $row->setRelation('supplier', $supplier);

                return $this->supplierOptionRow($row);
            }

            // No priced row yet — the supplier is still selectable, the app just
            // shows no price. Zeros, never nulls (the app casts with `as num`).
            return [
                'supplier_id' => $supplier->getKey(),
                'supplier' => (new SupplierResource($supplier))->toArray(request()),
                'supplier_name' => $supplier->name ?? '',
                'unit_price' => 0.0,
                'economy_price' => 0.0,
                'standard_price' => 0.0,
                'premium_price' => 0.0,
                'delivery_hours' => (int) ($supplier->default_delivery_hours ?? 0),
                'rating' => round((float) ($supplier->rating ?? 0), 1),
            ];
        })->values();
    }

    /**
     * One supplier option for the «choose supplier» picker, shaped identically
     * whether it came from supplier_items or supplier_products.
     *
     * The supplier goes through SupplierResource rather than out as a raw model:
     * the model's decimal casts emit strings and its unset columns emit nulls,
     * both of which the app's `as num` casts turn into a fatal
     * «type 'Null' is not a subtype of type 'num' in type cast».
     *
     * @param  \Modules\Purchase\Models\SupplierItem|\Modules\Supplier\Models\SupplierProduct  $row
     */
    private function supplierOptionRow($row): array
    {
        $supplier = $row->supplier;
        $unitPrice = (float) ($row->unit_price ?? 0);

        return [
            'supplier_id' => $row->supplier_id,
            'supplier' => $supplier ? (new SupplierResource($supplier))->toArray(request()) : null,
            'supplier_name' => $supplier?->name ?? '',
            'unit_price' => round($unitPrice, 2),
            'economy_price' => round((float) ($row->economy_price ?? $unitPrice), 2),
            'standard_price' => round((float) ($row->standard_price ?? $unitPrice), 2),
            'premium_price' => round((float) ($row->premium_price ?? $unitPrice), 2),
            'delivery_hours' => (int) ($row->delivery_hours ?? $supplier?->default_delivery_hours ?? 0),
            'rating' => round((float) ($row->rating ?? $supplier?->rating ?? 0), 1),
        ];
    }

    /**
     * Get branches with stock for internal transfer
     *
     * Returns branches with available stock including:
     * - Branch Name, Image, Manager Name
     * - Available Quantity (from daily inventory)
     * - Distance (with estimated travel time)
     * - Response Rate (speed of fulfilling requests)
     * - Rating
     * - Last Update (from daily inventory)
     *
     * @param  string  $itemId  BranchItem.id or Item.id
     * @param  float  $quantity  Required quantity
     * @param  string  $excludeBranchId  Branch to exclude (current branch)
     * @param  array  $filters  Additional filters
     */
    public function getBranchesWithStock(string $itemId, float $quantity, string $excludeBranchId, array $filters = []): Collection
    {
        // itemId can be either BranchItem.id (legacy) or Item.id (new structure)
        // Try to find the Item model
        $item = null;
        $branchItem = BranchItem::with('item')->find($itemId);

        if ($branchItem && $branchItem->item) {
            // New structure: BranchItem -> Item
            $item = $branchItem->item;
        } else {
            // Try direct Item lookup (new structure)
            $item = Item::find($itemId);
        }

        if (! $item) {
            return collect([]);
        }

        // Get current branch for distance calculation
        $currentBranch = \Modules\Branch\Models\Branch::find($excludeBranchId);
        $currentCoordinates = $this->parseCoordinates($currentBranch->map_coordinates ?? null);

        // Log warning if coordinates are missing (for debugging)
        if (! $currentCoordinates && $currentBranch) {
            Log::warning('Current branch missing map_coordinates', [
                'branch_id' => $excludeBranchId,
                'branch_name' => $currentBranch->name,
            ]);
        }

        // Get response rate and rating data from previous orders
        $branchStats = $this->getBranchStatsForInternalTransfer($item->id, $excludeBranchId);

        // Get average unit price for total amount calculation
        $avgUnitPrice = $this->getAverageUnitPriceForInternalTransfer($item->id);

        // Get branches with inventory data from Daily Quick Inventory
        // Performance: Use optimized query to get latest inventory data for all branches
        $branchesWithInventory = InventoryItem::where('item_id', $item->id)
            ->whereHas('inventorySession', function ($query) {
                $query->where('status', InventorySessionStatus::COMPLETED);
            })
            ->whereHas('branch', function ($query) use ($excludeBranchId) {
                $query->where('id', '!=', $excludeBranchId);
            })
            ->with(['branch.branchManager', 'inventorySession' => function ($query) {
                $query->orderBy('submitted_at', 'desc')
                    ->orderBy('end_time', 'desc');
            }])
            ->get()
            ->groupBy('branch_id')
            ->map(function ($items) {
                // Get the latest inventory item for this branch
                return $items->sortByDesc(function ($item) {
                    $session = $item->inventorySession;

                    return $session ? ($session->submitted_at ?? $session->end_time ?? $session->created_at) : null;
                })->first();
            })
            ->filter(function ($item) use ($quantity, $filters) {
                // Filter by minimum availability percentage if specified
                if (! empty($filters['min_availability'])) {
                    $minQuantity = $quantity * ($filters['min_availability'] / 100);

                    return (float) $item->quantity_inventory >= $minQuantity;
                }

                return (float) $item->quantity_inventory > 0;
            });

        // Search by branch name
        if (! empty($filters['search'])) {
            $branchesWithInventory = $branchesWithInventory->filter(function ($item) use ($filters) {
                $branchName = $item->branch->name ?? '';

                return stripos($branchName, $filters['search']) !== false;
            });
        }

        // Also get BranchInventory as fallback for branches without daily inventory
        $query = BranchInventory::with(['branch.branchManager', 'item'])
            ->where('item_id', $item->id)
            ->where('branch_id', '!=', $excludeBranchId)
            ->whereColumn('available_quantity', '>', 'reserved_quantity');

        // Exclude branches that already have inventory data
        if ($branchesWithInventory->isNotEmpty()) {
            $branchIdsWithInventory = $branchesWithInventory->pluck('branch_id')->toArray();
            $query->whereNotIn('branch_id', $branchIdsWithInventory);
        }

        // Filter by minimum availability percentage
        if (! empty($filters['min_availability'])) {
            $minQuantity = $quantity * ($filters['min_availability'] / 100);
            $query->where(DB::raw('(available_quantity - reserved_quantity)'), '>=', $minQuantity);
        }

        // Search by branch name
        if (! empty($filters['search'])) {
            $query->whereHas('branch', fn ($q) => $q->where('name', 'like', "%{$filters['search']}%"));

            // Also filter inventory items by search
            $branchesWithInventory = $branchesWithInventory->filter(function ($item) use ($filters) {
                $branchName = $item->branch->name ?? '';

                return stripos($branchName, $filters['search']) !== false;
            });
        }

        $inventories = $query->get();

        // Map branches with daily inventory data
        $inventoryBranches = $branchesWithInventory->map(function ($inventoryItem) use ($quantity, $currentCoordinates, $branchStats, $item, $avgUnitPrice, $filters) {
            $branch = $inventoryItem->branch;
            $branchId = $inventoryItem->branch_id;
            $session = $inventoryItem->inventorySession;

            // Get available quantity from daily inventory
            $availableQty = (float) $inventoryItem->quantity_inventory;

            // Get last update from inventory session
            $lastUpdate = $session
                ? ($session->submitted_at ?? $session->end_time ?? $session->updated_at)
                : null;

            // Calculate distance
            $targetCoordinates = $this->parseCoordinates($branch->map_coordinates ?? null);
            $distance = $this->calculateDistance($currentCoordinates, $targetCoordinates);

            // If distance is null (coordinates missing), use default values
            if (! $distance) {
                $distance = [
                    'distance_km' => PurchaseConstants::DEFAULT_DISTANCE_KM,
                    'estimated_hours' => (PurchaseConstants::DEFAULT_DISTANCE_KM / PurchaseConstants::AVERAGE_SPEED_KMH)
                        + PurchaseConstants::LOADING_UNLOADING_HOURS,
                ];
            }

            // Get branch manager info
            $manager = $branch->branchManager ?? $branch->managers()->active()->first();

            // Calculate total amount
            $totalAmount = $avgUnitPrice * $quantity;

            // Get response rate with default value
            $responseRate = $branchStats[$branchId]['response_rate']
                ?? PurchaseConstants::DEFAULT_RESPONSE_RATE;

            // Get rating with default value
            $rating = $branchStats[$branchId]['rating']
                ?? PurchaseConstants::DEFAULT_RATING;

            // Apply filters
            if ($this->shouldFilterByResponseTime($responseRate, $filters)) {
                return null;
            }

            if ($this->shouldFilterByDistance($distance, $filters)) {
                return null;
            }

            return [
                'branch_id' => $branchId,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'location' => $branch->location ?? null,
                    'lat' => $branch->lat ? (float) $branch->lat : null,
                    'lng' => $branch->lng ? (float) $branch->lng : null,
                    'image' => $branch->image ? asset('storage/'.$branch->image) : null,
                ] : null,
                'branch_manager' => $manager ? [
                    'id' => $manager->id,
                    'name' => $manager->name,
                    'image' => $manager->image_url ?? null,
                ] : null,
                // Item Details
                'item_id' => $item->id,
                'item_title' => $item->name,
                'item_code' => $item->code,
                'item_logo' => $item->logo_url,
                'quantity' => $quantity,
                'total_amount' => round($totalAmount, 2),
                // Available Quantity (from daily inventory)
                'available_quantity' => $availableQty,
                'available_quantity_label' => number_format($availableQty, 2).' '.($item->unit ?? 'kg'),
                'availability_percentage' => min(100, round(($availableQty / $quantity) * 100, 1)),
                'quality' => null, // Quality not available in daily inventory
                'expiry_date' => null, // Expiry date not available in daily inventory
                'cooling_status' => null, // Cooling status not available in daily inventory
                // Last Update (from daily inventory)
                'last_update' => $lastUpdate ? $lastUpdate->format('Y-m-d H:i:s') : null,
                // Store Details
                'distance' => $distance,
                'response_rate' => $responseRate,
                'rating' => $rating,
            ];
        })->filter(); // Remove null values from filters

        // If no results found by item_id, return branches that have this item in their BranchItem list
        if ($inventories->isEmpty() && $inventoryBranches->isEmpty()) {
            // Get all branches that have this item (by Item.id)
            $otherBranchesItems = BranchItem::with(['branch.branchManager', 'item'])
                ->where('item_id', $item->id)
                ->where('branch_id', '!=', $excludeBranchId)
                ->where('quantity', '>', 0)
                ->get();

            // Get all BranchInventory records for these branches and items (by Item.id)
            $branchIds = $otherBranchesItems->pluck('branch_id')->unique()->toArray();
            $itemIds = $otherBranchesItems->pluck('item_id')->unique()->toArray(); // Item.id (not BranchItem.id)

            $inventoriesByBranch = BranchInventory::whereIn('branch_id', $branchIds)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->keyBy(function ($inv) {
                    return $inv->branch_id.'_'.$inv->item_id;
                });

            return $otherBranchesItems->map(function ($branchItem) use ($quantity, $currentCoordinates, $branchStats, $item, $avgUnitPrice, $filters, $inventoriesByBranch) {
                $branch = $branchItem->branch;
                $branchId = $branchItem->branch_id;

                // Try to get inventory data for this branch and item
                $inventoryKey = $branchId.'_'.$branchItem->item_id;
                $inventory = $inventoriesByBranch[$inventoryKey] ?? null;

                // Use inventory data if available, otherwise use BranchItem quantity
                $availableQty = $inventory
                    ? (float) $inventory->actual_available
                    : (float) $branchItem->quantity;

                // Calculate distance
                $targetCoordinates = $this->parseCoordinates($branch->map_coordinates ?? null);
                $distance = $this->calculateDistance($currentCoordinates, $targetCoordinates);

                // If distance is null (coordinates missing), use default values
                if (! $distance) {
                    $distance = [
                        'distance_km' => PurchaseConstants::DEFAULT_DISTANCE_KM,
                        'estimated_hours' => (PurchaseConstants::DEFAULT_DISTANCE_KM / PurchaseConstants::AVERAGE_SPEED_KMH)
                            + PurchaseConstants::LOADING_UNLOADING_HOURS,
                    ];
                }

                // Get branch manager info
                $manager = $branch->branchManager ?? $branch->managers()->active()->first();

                // Calculate total amount
                $totalAmount = $avgUnitPrice * $quantity;

                // Get response rate with default value
                $responseRate = $branchStats[$branchId]['response_rate'] ?? 75.0; // Default 75% if no history

                // Get rating with default value
                $rating = $branchStats[$branchId]['rating'] ?? 4.5; // Default 4.5 if no history

                // Apply filters
                if ($this->shouldFilterByResponseTime($responseRate, $filters)) {
                    return null;
                }

                if ($this->shouldFilterByDistance($distance, $filters)) {
                    return null;
                }

                return [
                    'branch_id' => $branchId,
                    'branch' => $branch ? [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'location' => $branch->location ?? null,
                        'lat' => $branch->lat ? (float) $branch->lat : null,
                        'lng' => $branch->lng ? (float) $branch->lng : null,
                        'image' => $branch->image ? asset('storage/'.$branch->image) : null,
                    ] : null,
                    'branch_manager' => $manager ? [
                        'id' => $manager->id,
                        'name' => $manager->name,
                        'image' => $manager->image_url ?? null,
                    ] : null,
                    // Item Details
                    'item_id' => $item->id,
                    'item_title' => $item->name,
                    'item_code' => $item->code,
                    'item_logo' => $item->logo_url,
                    'quantity' => $quantity,
                    'total_amount' => round($totalAmount, 2),
                    // Available Quantity
                    'available_quantity' => $availableQty,
                    'available_quantity_label' => number_format($availableQty, 2).' '.($item->unit ?? 'kg'),
                    'availability_percentage' => min(100, round(($availableQty / $quantity) * 100, 1)),
                    'quality' => $inventory ? ($inventory->quality?->value ?? null) : null,
                    'expiry_date' => $inventory ? ($inventory->earliest_expiry_date?->format('Y-m-d') ?? null) : null,
                    'cooling_status' => $inventory ? ($inventory->cooling_status ?? null) : null,
                    'last_update' => $inventory
                        ? ($inventory->last_inventory_update?->format('Y-m-d H:i:s') ?? $item->updated_at?->format('Y-m-d H:i:s'))
                        : $item->updated_at?->format('Y-m-d H:i:s'),
                    // Store Details
                    'distance' => $distance,
                    // 'distance_km' => $distance ? round($distance['distance_km'], 2) : null,
                    // 'estimated_hours' => $distance ? round($distance['estimated_hours'], 1) : null,
                    'response_rate' => $responseRate,
                    'rating' => $rating,
                ];
            })->filter(); // Remove null values from filters
        }

        // Map BranchInventory records (fallback for branches without daily inventory)
        $branchInventoryBranches = $inventories->map(function ($inventory) use ($quantity, $currentCoordinates, $branchStats, $item, $avgUnitPrice, $filters) {
            $branch = $inventory->branch;
            $branchId = $inventory->branch_id;
            $inventoryItem = $inventory->item ?? $item; // Use Item from inventory or fallback to $item

            // Calculate distance
            $targetCoordinates = $this->parseCoordinates($branch->map_coordinates ?? null);
            $distance = $this->calculateDistance($currentCoordinates, $targetCoordinates);

            // If distance is null (coordinates missing), use default values
            if (! $distance) {
                $distance = [
                    'distance_km' => PurchaseConstants::DEFAULT_DISTANCE_KM,
                    'estimated_hours' => (PurchaseConstants::DEFAULT_DISTANCE_KM / PurchaseConstants::AVERAGE_SPEED_KMH)
                        + PurchaseConstants::LOADING_UNLOADING_HOURS,
                ];
            }

            // Get branch manager info
            $manager = $branch->branchManager ?? $branch->managers()->active()->first();

            // Calculate total amount
            $totalAmount = $avgUnitPrice * $quantity;

            // Get response rate with default value
            $responseRate = $branchStats[$branchId]['response_rate']
                ?? PurchaseConstants::DEFAULT_RESPONSE_RATE;

            // Get rating with default value
            $rating = $branchStats[$branchId]['rating']
                ?? PurchaseConstants::DEFAULT_RATING;

            // Apply filters
            if ($this->shouldFilterByResponseTime($responseRate, $filters)) {
                return null;
            }

            if ($this->shouldFilterByDistance($distance, $filters)) {
                return null;
            }

            $availableQty = (float) $inventory->actual_available;

            return [
                'branch_id' => $branchId,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'location' => $branch->location ?? null,
                    'image' => $branch->image ? asset('storage/'.$branch->image) : null,
                ] : null,
                'branch_manager' => $manager ? [
                    'id' => $manager->id,
                    'name' => $manager->name,
                    'image' => $manager->image_url ?? null,
                ] : null,
                // Item Details
                'item_id' => $item->id, // Item.id (central)
                'item_title' => $inventoryItem->name ?? $item->name,
                'item_code' => $inventoryItem->code ?? $item->code,
                'item_logo' => $inventoryItem->logo_url ?? $item->logo_url,
                'quantity' => $quantity,
                'total_amount' => round($totalAmount, 2),
                // Available Quantity
                'available_quantity' => $availableQty,
                'available_quantity_label' => number_format($availableQty, 2).' '.($inventoryItem->unit ?? $item->unit ?? 'kg'),
                'availability_percentage' => min(100, round(($availableQty / $quantity) * 100, 1)),
                'quality' => $inventory->quality?->value,
                'expiry_date' => $inventory->earliest_expiry_date?->format('Y-m-d'),
                'cooling_status' => $inventory->cooling_status,
                'last_update' => $inventory->last_inventory_update?->format('Y-m-d H:i:s'),
                // Store Details
                'distance' => $distance,
                'response_rate' => $responseRate,
                'rating' => $rating,
            ];
        })->filter(); // Remove null values from filters

        // Merge inventory branches (from Daily Inventory) with BranchInventory branches (fallback)
        // Use concat() instead of merge() because we're working with arrays, not models
        return $inventoryBranches->concat($branchInventoryBranches)->values();
    }

    /**
     * Parse coordinates from string format (e.g., "24.7136,46.6753" or "lat:24.7136,lng:46.6753")
     */
    private function parseCoordinates(?string $coordinates): ?array
    {
        if (empty($coordinates)) {
            return null;
        }

        // Try different formats
        if (preg_match('/(\d+\.?\d*)[,\s]+(\d+\.?\d*)/', $coordinates, $matches)) {
            return [
                'lat' => (float) $matches[1],
                'lng' => (float) $matches[2],
            ];
        }

        return null;
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     * Returns distance in km and estimated travel time in hours
     *
     * Performance: Uses caching to avoid recalculating same distances
     */
    private function calculateDistance(?array $from, ?array $to): ?array
    {
        if (! $from || ! $to) {
            return null;
        }

        // Performance: Create cache key from coordinates (rounded to 4 decimals for cache efficiency)
        $cacheKey = sprintf(
            'distance_%s_%s_%s_%s',
            round($from['lat'], 4),
            round($from['lng'], 4),
            round($to['lat'], 4),
            round($to['lng'], 4)
        );

        // Performance: Use cache to avoid recalculating same distances
        return Cache::remember($cacheKey, PurchaseConstants::CACHE_TTL_DISTANCE, function () use ($from, $to) {
            $earthRadius = 6371; // Earth radius in km

            $latFrom = deg2rad($from['lat']);
            $lonFrom = deg2rad($from['lng']);
            $latTo = deg2rad($to['lat']);
            $lonTo = deg2rad($to['lng']);

            $latDelta = $latTo - $latFrom;
            $lonDelta = $lonTo - $lonFrom;

            $a = sin($latDelta / 2) ** 2 +
                cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

            $distanceKm = $earthRadius * $c;

            // Estimate travel time using constants
            $estimatedHours = ($distanceKm / PurchaseConstants::AVERAGE_SPEED_KMH_HIGHWAY)
                + PurchaseConstants::LOADING_UNLOADING_HOURS;

            return [
                'distance_km' => $distanceKm,
                'estimated_hours' => $estimatedHours,
            ];
        });
    }

    /**
     * Get branch statistics for internal transfer (response rate and rating)
     */
    private function getBranchStatsForInternalTransfer(string $itemId, string $excludeBranchId): array
    {
        $sixMonthsAgo = now()->subMonths(PurchaseConstants::BRANCH_STATS_MONTHS);

        // itemId is now Item.id (central), but PurchaseOrderItem.item_id might still reference BranchItem.id
        // We need to match by Item.id through the item relationship
        // For now, match by item_id directly (assuming PurchaseOrderItem.item_id references Item.id)
        $orders = PurchaseOrder::where('order_type', OrderType::INTERNAL_TRANSFER)
            ->whereHas('items', function ($query) use ($itemId) {
                // Match by item_id (assuming it references Item.id in new structure)
                $query->where('item_id', $itemId);
            })
            ->where(function ($query) use ($excludeBranchId) {
                $query->where('from_branch_id', '!=', $excludeBranchId)
                    ->orWhere('to_branch_id', '!=', $excludeBranchId);
            })
            ->where('created_at', '>=', $sixMonthsAgo)
            ->whereIn('status', [
                OrderStatus::CONFIRMED,
                OrderStatus::PARTIAL_CONFIRMATION,
                OrderStatus::CLOSED,
                OrderStatus::DELIVERED,
            ])
            ->get();

        $stats = [];

        foreach ($orders as $order) {
            $branchId = $order->from_branch_id;

            if (! isset($stats[$branchId])) {
                $stats[$branchId] = [
                    'response_times' => [],
                    'ratings' => [],
                ];
            }

            // Calculate response time (from created_at to confirmed_at)
            if ($order->created_at && $order->confirmed_at) {
                $responseTime = $order->created_at->diffInHours($order->confirmed_at);
                $stats[$branchId]['response_times'][] = $responseTime;
            }
        }

        // Calculate averages
        $result = [];
        foreach ($stats as $branchId => $data) {
            // Response rate: percentage of orders responded to within 24 hours
            $respondedWithin24h = count(array_filter(
                $data['response_times'],
                fn ($t) => $t <= PurchaseConstants::HOURS_PER_DAY
            ));
            $totalOrders = count($data['response_times']);
            $responseRate = $totalOrders > 0
                ? round(($respondedWithin24h / $totalOrders) * 100, 1)
                : null;

            // Rating: default to constant if no rating system exists
            $rating = PurchaseConstants::DEFAULT_RATING;

            $result[$branchId] = [
                'response_rate' => $responseRate,
                'rating' => $rating,
            ];
        }

        return $result;
    }

    /**
     * Record price for history
     */
    public function recordPrice(
        string $itemId,
        string $itemName,
        OrderType $sourceType,
        ?string $sourceId,
        ?string $sourceName,
        float $unitPrice,
        ?QualityLevel $quality = null,
        ?int $deliveryDays = null,
        ?float $rating = null
    ): PriceHistory {
        return PriceHistory::recordPrice(
            $itemId,
            $itemName,
            $sourceType,
            $sourceId,
            $sourceName,
            $unitPrice,
            $quality,
            'kg',
            $deliveryDays,
            $rating
        );
    }

    /**
     * Get average unit price for internal transfer orders
     */
    private function getAverageUnitPriceForInternalTransfer(string $itemId): float
    {
        $threeMonthsAgo = now()->subMonths(PurchaseConstants::PRICE_HISTORY_MONTHS);

        // Performance: Use optimized DB query instead of Eloquent
        $avgPrice = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_order_items.item_id', $itemId)
            ->where('purchase_orders.created_at', '>=', $threeMonthsAgo)
            ->where('purchase_orders.order_type', OrderType::INTERNAL_TRANSFER->value)
            ->whereIn('purchase_orders.status', [
                OrderStatus::CONFIRMED->value,
                OrderStatus::PARTIAL_CONFIRMATION->value,
                OrderStatus::CLOSED->value,
                OrderStatus::DELIVERED->value,
            ])
            ->avg('purchase_order_items.unit_price');

        return $avgPrice ? round((float) $avgPrice, 2) : 0;
    }

    /**
     * Check if branch should be filtered by response time
     */
    private function shouldFilterByResponseTime(?float $responseRate, array $filters): bool
    {
        if (empty($filters['response_time'])) {
            return false;
        }

        if ($responseRate === null) {
            // If no response rate data, exclude from "Fast" filter only
            return $filters['response_time'] === 'fast';
        }

        return match ($filters['response_time']) {
            'fast' => $responseRate < PurchaseConstants::FAST_RESPONSE_RATE_THRESHOLD,
            'normal' => $responseRate < PurchaseConstants::NORMAL_RESPONSE_RATE_MIN
                || $responseRate >= PurchaseConstants::FAST_RESPONSE_RATE_THRESHOLD,
            'slow' => $responseRate >= PurchaseConstants::NORMAL_RESPONSE_RATE_MIN,
            default => false,
        };
    }

    /**
     * Check if branch should be filtered by distance
     */
    private function shouldFilterByDistance(?array $distance, array $filters): bool
    {
        if (empty($filters['max_distance_km'])) {
            return false;
        }

        if ($distance === null) {
            return false; // Don't filter if distance can't be calculated
        }

        return $distance['distance_km'] > $filters['max_distance_km'];
    }

    /**
     * Save a price comparison snapshot.
     *
     * Recomputes the comparison server-side from the item id and persists the
     * full result, so the saved record stays stable even if prices change.
     */
    public function saveComparison(
        string $itemId,
        ?float $quantity,
        string $branchId,
        string $userId,
        ?string $note = null
    ): SavedPriceComparison {
        $comparison = $this->comparePrices($itemId, $quantity, $branchId);

        // comparePrices() returns a null item_name when the id resolves to no item.
        if (empty($comparison['item_name'])) {
            throw new \InvalidArgumentException('Item not found for the given item_id.');
        }

        return SavedPriceComparison::create([
            'branch_id' => $branchId,
            'created_by' => $userId,
            'item_id' => $comparison['item_id'],
            'item_name' => $comparison['item_name'],
            'quantity' => $comparison['quantity'],
            'note' => $note,
            'snapshot' => $comparison,
        ]);
    }

    /**
     * Get a paginated list of saved comparisons for a branch.
     */
    public function getSavedComparisons(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        return SavedPriceComparison::forBranch($branchId)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get a single saved comparison, scoped to the branch (tenant isolation).
     */
    public function getSavedComparison(string $id, string $branchId): ?SavedPriceComparison
    {
        return SavedPriceComparison::forBranch($branchId)->find($id);
    }

    /**
     * Delete a saved comparison, scoped to the branch (tenant isolation).
     *
     * @return bool false when no matching record exists for this branch
     */
    public function deleteSavedComparison(string $id, string $branchId): bool
    {
        $saved = SavedPriceComparison::forBranch($branchId)->find($id);

        if (! $saved) {
            return false;
        }

        return (bool) $saved->delete();
    }
}
