<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Collection;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\PriceHistory;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseSupplier;
use Modules\Purchase\Models\SupplierItem;

class PriceComparisonService
{
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
    public function comparePrices(string $itemId, ?float $quantity = null): array
    {
        // Use default quantity of 1 if not provided
        $quantity = $quantity ?? 1.0;

        // Get item name from BranchItem
        $item = BranchItem::find($itemId);
        $itemName = $item ? $item->item_name : null;

        $comparison = [
            'item_id' => $itemId,
            'item_name' => $itemName,
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
        $supplierPrices = $this->getSupplierPrices($itemId, $quantity);
        if ($supplierPrices->isNotEmpty()) {
            $comparison['sources']['direct_supplier'] = $supplierPrices->toArray();
        }

        // Via Purchasing Officer (average/estimated prices from actual orders from all branches)
        $poPrices = $this->getPurchasingOfficerPrices($itemId);
        if ($poPrices) {
            $comparison['sources']['via_purchasing_officer'] = $poPrices;
        }

        // Internal Transfer options (from all branches)
        $transferOptions = $this->getInternalTransferOptions($itemId, $quantity);
        if ($transferOptions->isNotEmpty()) {
            $comparison['sources']['internal_transfer'] = $transferOptions->toArray();
        }

        // Get price trends from actual purchase orders (from all branches)
        $comparison['price_trends'] = $this->getPriceTrends($itemId);

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
        $supplierItems = SupplierItem::with('supplier')
            ->byItem($itemId)
            ->available()
            ->whereHas('supplier', fn($q) => $q->active())
            ->get()
            ->filter(fn($item) => $item->isWithinQuantityLimits($quantity));

        // Get actual order history for this item from last 3 months (from all branches)
        $threeMonthsAgo = now()->subMonths(3);
        $orderItems = PurchaseOrderItem::with(['purchaseOrder.supplier'])
            ->where('item_id', $itemId)
            ->whereHas('purchaseOrder', function ($query) use ($threeMonthsAgo) {
                $query->where('order_type', OrderType::DIRECT_SUPPLIER)
                    ->where('created_at', '>=', $threeMonthsAgo)
                    ->whereIn('status', [
                        OrderStatus::CONFIRMED,
                        OrderStatus::PARTIAL_CONFIRMATION,
                        OrderStatus::CLOSED,
                        OrderStatus::DELIVERED,
                    ]);
            })
            ->get();

        // Group order items by supplier_id
        $supplierHistory = $orderItems->groupBy(function ($item) {
            return $item->purchaseOrder->supplier_id;
        });

        // Enhance supplier items with actual order data
        return $supplierItems->map(function ($supplierItem) use ($supplierHistory, $quantity) {
            $supplierId = $supplierItem->supplier_id;
            $history = $supplierHistory->get($supplierId, collect());

            // Calculate average delivery days from actual orders
            $avgDeliveryDays = null;
            if ($history->isNotEmpty()) {
                $deliveryDays = $history->map(function ($item) {
                    $order = $item->purchaseOrder;
                    $createdAt = $order->created_at;
                    $completedAt = $order->confirmed_at ?? $order->received_at ?? $order->closed_at ?? now();
                    return $createdAt->diffInDays($completedAt);
                })->avg();
                $avgDeliveryDays = round($deliveryDays, 1);
            }

            // Get rating from supplier or calculate from orders
            $rating = $supplierItem->rating ?? $supplierItem->supplier->rating ?? 4.0;

            return [
                'supplier_id' => $supplierId,
                'supplier_name' => $supplierItem->supplier->name,
                'supplier_status' => $supplierItem->supplier->status->value,
                'unit_price' => $supplierItem->unit_price,
                'total_price' => $supplierItem->unit_price * $quantity,
                'delivery_hours' => $supplierItem->delivery_hours,
                'delivery_days' => $avgDeliveryDays ?? ceil($supplierItem->delivery_hours / 24),
                'rating' => round($rating, 1),
                'is_available' => $supplierItem->is_available,
                'order_count' => $history->count(),
            ];
        });
    }

    /**
     * Get purchasing officer prices from actual purchase orders
     */
    private function getPurchasingOfficerPrices(string $itemId): ?array
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

            if (!$history) {
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
     */
    private function getInternalTransferOptions(string $itemId, float $quantity): Collection
    {
        return BranchInventory::with('branch')
            ->byItem($itemId)
            ->available()
            ->get()
            ->filter(fn($inv) => $inv->actual_available >= $quantity * 0.6) // At least 60% availability
            ->map(function ($inventory) use ($quantity) {
                return [
                    'branch_id' => $inventory->branch_id,
                    'branch_name' => $inventory->branch->name,
                    'branch_image' => $inventory->branch->image,
                    'available_quantity' => $inventory->actual_available,
                    'availability_percentage' => min(100, ($inventory->actual_available / $quantity) * 100),
                    'quality' => $inventory->quality?->value,
                    'expiry_date' => $inventory->earliest_expiry_date?->format('Y-m-d'),
                    'cooling_status' => $inventory->cooling_status,
                    'last_update' => $inventory->last_inventory_update?->diffForHumans(),
                    'unit_price' => 0, // Internal transfers are free
                    'total_price' => 0,
                    'rating' => null, // Would come from branch manager stats
                    'response_rate' => null,
                    'distance' => null, // Would be calculated from coordinates
                ];
            });
    }

    /**
     * Get price trends for last 3 months from actual purchase orders
     * Returns actual prices that were paid for this item in the last 3 months (from all branches)
     */
    public function getPriceTrends(string $itemId): array
    {
        $trends = [];
        $threeMonthsAgo = now()->subMonths(3)->startOfMonth();

        // Get all order items for this item in the last 3 months (from all branches)
        // Only include completed/confirmed orders (not drafts or canceled)
        $orderItems = PurchaseOrderItem::with(['purchaseOrder'])
            ->where('item_id', $itemId)
            ->whereHas('purchaseOrder', function ($query) use ($threeMonthsAgo) {
                $query->where('created_at', '>=', $threeMonthsAgo)
                    ->whereIn('status', [
                        OrderStatus::CONFIRMED,
                        OrderStatus::PARTIAL_CONFIRMATION,
                        OrderStatus::CLOSED,
                        OrderStatus::DELIVERED,
                    ]);
            })
            ->get();

        // Group by month and order type
        for ($i = 0; $i < 3; $i++) {
            $month = now()->subMonths($i);
            $periodMonth = $month->format('Y-m');
            $monthLabel = $month->format('M Y');
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            // Filter items for this month
            $monthItems = $orderItems->filter(function ($item) use ($monthStart, $monthEnd) {
                $orderDate = $item->purchaseOrder->created_at;
                return $orderDate >= $monthStart && $orderDate <= $monthEnd;
            });

            // Group by order type
            $directSupplierItems = $monthItems->filter(function ($item) {
                return $item->purchaseOrder->order_type === OrderType::DIRECT_SUPPLIER;
            });

            $viaPOItems = $monthItems->filter(function ($item) {
                return $item->purchaseOrder->order_type === OrderType::VIA_PURCHASING_OFFICER;
            });

            $internalTransferItems = $monthItems->filter(function ($item) {
                return $item->purchaseOrder->order_type === OrderType::INTERNAL_TRANSFER;
            });

            // Calculate average prices
            $directSupplierAvg = $directSupplierItems->isNotEmpty()
                ? $directSupplierItems->avg('unit_price')
                : null;

            $viaPOAvg = $viaPOItems->isNotEmpty()
                ? $viaPOItems->avg('unit_price')
                : null;

            $internalTransferAvg = $internalTransferItems->isNotEmpty()
                ? $internalTransferItems->avg('unit_price')
                : 0; // Internal transfers are usually free

            $trends[$periodMonth] = [
                'month' => $monthLabel,
                'direct_supplier' => $directSupplierAvg,
                'via_purchasing_officer' => $viaPOAvg,
                'internal_transfer' => $internalTransferAvg,
                'data_points' => [
                    'direct_supplier' => $directSupplierItems->count(),
                    'via_purchasing_officer' => $viaPOItems->count(),
                    'internal_transfer' => $internalTransferItems->sum('purchase_order.total_price'),
                    'direct_supplier' => $directSupplierItems->sum('purchase_order.total_price'),
                    'via_purchasing_officer' => $viaPOItems->sum('purchase_order.total_price'),
                    'internal_transfer' => $internalTransferItems->sum('purchase_order.total_price'),
                ],
            ];
        }

        return array_reverse($trends);
    }

    /**
     * Build comparison table with all order types
     */
    private function buildComparisonTable(array $sources, float $quantity): array
    {
        $table = [];

        // Direct Supplier - get best option (lowest price or highest rating)
        if (!empty($sources['direct_supplier'])) {
            $bestSupplier = collect($sources['direct_supplier'])
                ->sortBy('unit_price')
                ->first();

            $table[] = [
                'order_type' => 'direct_supplier',
                'order_type_label' => OrderType::DIRECT_SUPPLIER->label(),
                'price' => $bestSupplier['unit_price'],
                'total_price' => $bestSupplier['unit_price'] * $quantity,
                'delivery_days' => $bestSupplier['delivery_days'],
                'rating' => $bestSupplier['rating'],
                'supplier_name' => $bestSupplier['supplier_name'] ?? null,
            ];
        }

        // Via Purchasing Officer
        if (!empty($sources['via_purchasing_officer'])) {
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
        if (!empty($sources['internal_transfer'])) {
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
     */
    private function calculatePriceChange(array $trends): ?array
    {
        if (empty($trends)) {
            return null;
        }

        $trendsArray = array_values($trends);
        if (count($trendsArray) < 2) {
            return null;
        }

        // Get first and last month prices (average across all types)
        $firstMonth = $trendsArray[0];
        $lastMonth = $trendsArray[count($trendsArray) - 1];

        $firstMonthPrice = $this->getAveragePriceForMonth($firstMonth);
        $lastMonthPrice = $this->getAveragePriceForMonth($lastMonth);

        if ($firstMonthPrice === null || $lastMonthPrice === null || $firstMonthPrice == 0) {
            return null;
        }

        $change = (($lastMonthPrice - $firstMonthPrice) / $firstMonthPrice) * 100;
        $isIncrease = $change > 0;

        return [
            'percentage' => round(abs($change), 1),
            'is_increase' => $isIncrease,
            'label' => $isIncrease
                ? "+" . round($change, 1) . "% Price Increase"
                : round($change, 1) . "% Price Decrease",
            'status' => $isIncrease ? "HIGHER THAN LAST MONTH" : "LOWER THAN LAST MONTH",
        ];
    }

    /**
     * Get average price for a month across all order types
     */
    private function getAveragePriceForMonth(array $monthData): ?float
    {
        $prices = array_filter([
            $monthData['direct_supplier'] ?? null,
            $monthData['via_purchasing_officer'] ?? null,
            $monthData['internal_transfer'] ?? null,
        ], fn($price) => $price !== null && $price > 0);

        if (empty($prices)) {
            return null;
        }

        return array_sum($prices) / count($prices);
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
        if (!empty($sources['direct_supplier'])) {
            foreach ($sources['direct_supplier'] as $option) {
                $allOptions[] = array_merge($option, ['type' => 'direct_supplier']);
            }
        }

        if (!empty($sources['via_purchasing_officer'])) {
            $allOptions[] = array_merge($sources['via_purchasing_officer'], ['type' => 'via_purchasing_officer']);
        }

        if (!empty($sources['internal_transfer'])) {
            foreach ($sources['internal_transfer'] as $option) {
                $allOptions[] = array_merge($option, ['type' => 'internal_transfer']);
            }
        }

        if (empty($allOptions)) {
            return $insights;
        }

        // Find lowest price
        $lowestPrice = collect($allOptions)->sortBy('unit_price')->first();
        $insights['lowest_price'] = [
            'type' => $lowestPrice['type'],
            'value' => $lowestPrice['unit_price'],
            'label' => OrderType::from($lowestPrice['type'])->label(),
        ];

        // Find fastest delivery
        $fastest = collect($allOptions)
            ->filter(fn($o) => isset($o['delivery_days']))
            ->sortBy('delivery_days')
            ->first();

        if ($fastest) {
            $insights['fastest_delivery'] = [
                'type' => $fastest['type'],
                'value' => $fastest['delivery_days'],
                'label' => OrderType::from($fastest['type'])->label(),
            ];
        }

        // Find best rating
        $bestRated = collect($allOptions)
            ->filter(fn($o) => isset($o['rating']))
            ->sortByDesc('rating')
            ->first();

        if ($bestRated) {
            $insights['best_rating'] = [
                'type' => $bestRated['type'],
                'value' => $bestRated['rating'],
                'label' => OrderType::from($bestRated['type'])->label(),
            ];
            $insights['best_compliance'] = $insights['best_rating'];
        }

        return $insights;
    }

    /**
     * Determine best overall option
     */
    private function determineBestOption(array $sources): ?array
    {
        $allOptions = [];

        if (!empty($sources['direct_supplier'])) {
            foreach ($sources['direct_supplier'] as $option) {
                $allOptions[] = array_merge($option, ['type' => 'direct_supplier']);
            }
        }

        if (!empty($sources['via_purchasing_officer'])) {
            $allOptions[] = array_merge($sources['via_purchasing_officer'], ['type' => 'via_purchasing_officer']);
        }

        if (!empty($sources['internal_transfer'])) {
            foreach ($sources['internal_transfer'] as $option) {
                $allOptions[] = array_merge($option, ['type' => 'internal_transfer']);
            }
        }

        if (empty($allOptions)) {
            return null;
        }

        // Score each option (lower is better for price/delivery, higher is better for rating)
        $scored = collect($allOptions)->map(function ($option) {
            $priceScore = $option['unit_price'] ?? 0;
            $deliveryScore = ($option['delivery_days'] ?? 3) * 10;
            $ratingScore = 100 - (($option['rating'] ?? 3) * 20);

            return array_merge($option, [
                'composite_score' => ($priceScore * 0.4) + ($deliveryScore * 0.3) + ($ratingScore * 0.3),
            ]);
        });

        $best = $scored->sortBy('composite_score')->first();

        return [
            'type' => $best['type'],
            'type_label' => OrderType::from($best['type'])->label(),
            'reason' => 'Best combination of price, delivery time, and rating',
        ];
    }

    /**
     * Get suppliers for an item
     */
    public function getSuppliers(string $itemId, array $filters = []): Collection
    {
        $query = SupplierItem::with('supplier')
            ->byItem($itemId)
            ->available()
            ->whereHas('supplier', fn($q) => $q->active());

        // Filter by supplier status
        if (!empty($filters['status'])) {
            $query->whereHas('supplier', fn($q) => $q->byStatus($filters['status']));
        }

        // Filter by delivery time
        if (!empty($filters['max_delivery_hours'])) {
            $query->byDeliveryTime($filters['max_delivery_hours']);
        }

        // Search by supplier name
        if (!empty($filters['search'])) {
            $query->whereHas('supplier', fn($q) => $q->search($filters['search']));
        }

        return $query->get()->map(function ($item) {
            return [
                'supplier_id' => $item->supplier_id,
                'supplier' => $item->supplier,
                'unit_price' => $item->unit_price,
                'economy_price' => $item->economy_price,
                'standard_price' => $item->standard_price,
                'premium_price' => $item->premium_price,
                'delivery_hours' => $item->delivery_hours,
                'rating' => $item->rating ?? $item->supplier->rating,
            ];
        });
    }

    /**
     * Get branches with stock for internal transfer
     *
     * itemId should be BranchItem.id
     * We search in BranchInventory where item_id matches BranchItem.id
     */
    public function getBranchesWithStock(string $itemId, float $quantity, string $excludeBranchId, array $filters = []): Collection
    {
        // Get BranchItem to get item_name for matching
        $branchItem = BranchItem::find($itemId);

        if (!$branchItem) {
            return collect([]);
        }

        // First, try to find BranchInventory records where item_id matches BranchItem.id
        $query = BranchInventory::with(['branch'])
            ->where('item_id', $itemId)
            ->where('branch_id', '!=', $excludeBranchId)
            ->whereRaw('(available_quantity - reserved_quantity) > 0');

        // Filter by minimum availability percentage
        if (!empty($filters['min_availability'])) {
            $minQuantity = $quantity * ($filters['min_availability'] / 100);
            $query->whereRaw('(available_quantity - reserved_quantity) >= ?', [$minQuantity]);
        }

        // Search by branch name
        if (!empty($filters['search'])) {
            $query->whereHas('branch', fn($q) => $q->where('name', 'like', "%{$filters['search']}%"));
        }

        $inventories = $query->get();

        // If no results found by item_id, return branches that have this item in their BranchItem list
        // This handles the case where BranchInventory.item_id doesn't match BranchItem.id
        if ($inventories->isEmpty()) {
            // Get all branches that have this item (by item_name)
            $otherBranchesItems = BranchItem::with('branch')
                ->where('item_name', $branchItem->item_name)
                ->where('branch_id', '!=', $excludeBranchId)
                ->where('item_quantity', '>', 0)
                ->get();

            return $otherBranchesItems->map(function ($item) use ($quantity) {
                $branch = $item->branch;
                $availableQty = (float) $item->item_quantity;

                return [
                    'branch_id' => $item->branch_id,
                    'branch' => $branch ? [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'location' => $branch->location ?? null,
                        'image' => $branch->image_url ?? null,
                    ] : null,
                    'branch_manager' => null, // Will be loaded if needed
                    'available_quantity' => $availableQty,
                    'availability_percentage' => min(100, round(($availableQty / $quantity) * 100, 1)),
                    'quality' => null,
                    'expiry_date' => null,
                    'cooling_status' => null,
                    'last_update' => $item->updated_at?->format('Y-m-d H:i:s'),
                    'distance' => null,
                    'response_rate' => null,
                    'rating' => null,
                ];
            });
        }

        return $inventories->map(function ($inventory) use ($quantity) {
            $branch = $inventory->branch;

            return [
                'branch_id' => $inventory->branch_id,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'location' => $branch->location ?? null,
                    'image' => $branch->image_url ?? null,
                ] : null,
                'branch_manager' => null,
                'available_quantity' => (float) $inventory->actual_available,
                'availability_percentage' => min(100, round(($inventory->actual_available / $quantity) * 100, 1)),
                'quality' => $inventory->quality?->value,
                'expiry_date' => $inventory->earliest_expiry_date?->format('Y-m-d'),
                'cooling_status' => $inventory->cooling_status,
                'last_update' => $inventory->last_inventory_update?->format('Y-m-d H:i:s'),
                'distance' => null,
                'response_rate' => null,
                'rating' => null,
            ];
        });
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
}
