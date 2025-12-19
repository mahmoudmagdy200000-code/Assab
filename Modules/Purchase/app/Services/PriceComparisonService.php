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
    public function comparePrices(string $itemId, ?float $quantity = null, ?string $excludeBranchId = null): array
    {
        // Use default quantity of 1 if not provided
        $quantity = $quantity ?? 1.0;

        // Get item details from BranchItem
        $item = BranchItem::find($itemId);

        // Handle item_logo - can be array or string
        $itemLogo = null;
        if ($item && $item->item_logo) {
            if (is_array($item->item_logo)) {
                $logo = $item->item_logo[0] ?? null;
            } else {
                $logo = $item->item_logo;
            }

            if ($logo) {
                $itemLogo = str_starts_with($logo, 'http')
                    ? $logo
                    : asset('storage/' . $logo);
            }
        }

        $comparison = [
            'item_id' => $itemId,
            'item_name' => $item ? $item->item_name : null,
            'item_code' => $item ? $item->item_code : null,
            'item_unit' => $item ? $item->item_unit : null,
            'item_logo' => $itemLogo,
            'item_price' => $item ? (float) $item->item_price : null,
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

        // Internal Transfer options (from all branches except current)
        $transferOptions = $this->getInternalTransferOptions($itemId, $quantity, $excludeBranchId);
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
     * Uses actual prices from previous orders if available
     */
    private function getInternalTransferOptions(string $itemId, float $quantity, ?string $excludeBranchId = null): Collection
    {
        // Get actual average price from previous internal transfer orders (last 3 months)
        $threeMonthsAgo = now()->subMonths(3);
        $orderItems = PurchaseOrderItem::with(['purchaseOrder'])
            ->where('item_id', $itemId)
            ->whereHas('purchaseOrder', function ($query) use ($threeMonthsAgo) {
                $query->where('order_type', OrderType::INTERNAL_TRANSFER)
                    ->where('created_at', '>=', $threeMonthsAgo)
                    ->whereIn('status', [
                        OrderStatus::CONFIRMED,
                        OrderStatus::PARTIAL_CONFIRMATION,
                        OrderStatus::CLOSED,
                        OrderStatus::DELIVERED,
                    ]);
            })
            ->get();

        // Calculate average unit price from actual orders
        $avgUnitPrice = $orderItems->isNotEmpty()
            ? round($orderItems->avg('unit_price'), 2)
            : 0; // Default to 0 if no previous orders

        return BranchInventory::with('branch')
            ->byItem($itemId)
            ->available()
            ->when($excludeBranchId, fn($q) => $q->where('branch_id', '!=', $excludeBranchId))
            ->get()
            ->filter(fn($inv) => $inv->actual_available >= $quantity * 0.6) // At least 60% availability
            ->map(function ($inventory) use ($quantity, $avgUnitPrice) {
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
                    'unit_price' => $avgUnitPrice, // Use actual average price from previous orders
                    'total_price' => $avgUnitPrice * $quantity,
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
        $threeMonthsAgo = now()->subMonths(2)->startOfMonth(); // current month + last 2 months

        // Get all completed/confirmed orders for this item within the last 2 months and current month
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

        // For each of the last two months + current month, pick the latest purchase in that month
        $trends = collect();

        for ($i = 0; $i <= 2; $i++) {
            $month = now()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            // Filter items for this month and get the latest purchase
            $latestItem = $orderItems
                ->filter(function ($item) use ($monthStart, $monthEnd) {
                    $orderDate = $item->purchaseOrder->created_at;
                    return $orderDate >= $monthStart && $orderDate <= $monthEnd;
                })
                ->sortByDesc(function ($item) {
                    return $item->purchaseOrder->created_at;
                })
                ->first();

            if ($latestItem) {
                $purchaseDate = $latestItem->purchaseOrder->created_at;
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

        if (!$isNewFormat) {
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
                ? "+" . round($change, 1) . "% Price Increase"
                : round($change, 1) . "% Price Decrease",
            'status' => $isIncrease ? "HIGHER THAN FIRST PURCHASE" : "LOWER THAN FIRST PURCHASE",
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
        $insights['lowest_price'] = array_merge(
            $this->mapSourceMeta($lowestPrice),
            [
                'value' => $lowestPrice['unit_price'],
                'label' => OrderType::from($lowestPrice['type'])->label(),
            ]
        );

        // Find fastest delivery
        $fastest = collect($allOptions)
            ->filter(fn($o) => isset($o['delivery_days']))
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
            ->filter(fn($o) => isset($o['rating']))
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
     * Returns branches with available stock including:
     * - Branch Name, Image, Manager Name
     * - Available Quantity
     * - Distance (with estimated travel time)
     * - Response Rate (speed of fulfilling requests)
     * - Rating
     * - Last Update
     *
     * @param string $itemId BranchItem.id
     * @param float $quantity Required quantity
     * @param string $excludeBranchId Branch to exclude (current branch)
     * @param array $filters Additional filters
     * @return Collection
     */
    public function getBranchesWithStock(string $itemId, float $quantity, string $excludeBranchId, array $filters = []): Collection
    {
        // Get BranchItem to get item_name for matching
        $branchItem = BranchItem::find($itemId);

        if (!$branchItem) {
            return collect([]);
        }

        // Get current branch for distance calculation
        $currentBranch = \Modules\Branch\Models\Branch::find($excludeBranchId);
        $currentCoordinates = $this->parseCoordinates($currentBranch->map_coordinates ?? null);

        // Log warning if coordinates are missing (for debugging)
        if (!$currentCoordinates && $currentBranch) {
            \Log::warning('Current branch missing map_coordinates', [
                'branch_id' => $excludeBranchId,
                'branch_name' => $currentBranch->name,
            ]);
        }

        // Get response rate and rating data from previous orders
        $branchStats = $this->getBranchStatsForInternalTransfer($itemId, $excludeBranchId);

        // Get average unit price for total amount calculation
        $avgUnitPrice = $this->getAverageUnitPriceForInternalTransfer($itemId);

        // First, try to find BranchInventory records where item_id matches BranchItem.id
        $query = BranchInventory::with(['branch.branchManager'])
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
        if ($inventories->isEmpty()) {
            // Get all branches that have this item (by item_name)
            $otherBranchesItems = BranchItem::with(['branch.branchManager'])
                ->where('item_name', $branchItem->item_name)
                ->where('branch_id', '!=', $excludeBranchId)
                ->where('item_quantity', '>', 0)
                ->get();

            return $otherBranchesItems->map(function ($item) use ($quantity, $currentCoordinates, $branchStats, $branchItem, $avgUnitPrice, $filters) {
                $branch = $item->branch;
                $availableQty = (float) $item->item_quantity;
                $branchId = $item->branch_id;

                // Calculate distance
                $targetCoordinates = $this->parseCoordinates($branch->map_coordinates ?? null);
                $distance = $this->calculateDistance($currentCoordinates, $targetCoordinates);

                // If distance is null (coordinates missing), use default values
                if (!$distance) {
                    $distance = [
                        'distance_km' => 50.0, // Default 50 km if coordinates not available
                        'estimated_hours' => 1.0, // Default 1 hour
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
                        'image' => $branch->image ? asset('storage/' . $branch->image) : null,
                    ] : null,
                    'branch_manager' => $manager ? [
                        'id' => $manager->id,
                        'name' => $manager->name,
                        'image' => $manager->image_url ?? null,
                    ] : null,
                    // Item Details
                    'item_id' => $branchItem->id,
                    'item_title' => $branchItem->item_name,
                    'item_code' => $branchItem->item_code,
                    'item_logo' => $branchItem->item_logo_url,
                    'quantity' => $quantity,
                    'total_amount' => round($totalAmount, 2),
                    // Available Quantity
                    'available_quantity' => $availableQty,
                    'available_quantity_label' => number_format($availableQty, 2) . ' ' . ($item->item_unit ?? 'kg'),
                    'availability_percentage' => min(100, round(($availableQty / $quantity) * 100, 1)),
                    'quality' => null,
                    'expiry_date' => null,
                    'cooling_status' => null,
                    'last_update' => $item->updated_at?->format('Y-m-d H:i:s'),
                    // Store Details
                    'distance' => $distance,
                    // 'distance_km' => $distance ? round($distance['distance_km'], 2) : null,
                    'estimated_hours' => $distance ? round($distance['estimated_hours'], 1) : null,
                    'response_rate' => $responseRate,
                    'rating' => $rating,
                ];
            })->filter(); // Remove null values from filters
        }

        return $inventories->map(function ($inventory) use ($quantity, $currentCoordinates, $branchStats, $branchItem, $avgUnitPrice, $filters) {
            $branch = $inventory->branch;
            $branchId = $inventory->branch_id;

            // Calculate distance
            $targetCoordinates = $this->parseCoordinates($branch->map_coordinates ?? null);
            $distance = $this->calculateDistance($currentCoordinates, $targetCoordinates);

            // If distance is null (coordinates missing), use default values
            if (!$distance) {
                $distance = [
                    'distance_km' => 50.0, // Default 50 km if coordinates not available
                    'estimated_hours' => 1.0, // Default 1 hour
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

            $availableQty = (float) $inventory->actual_available;

            return [
                'branch_id' => $branchId,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'location' => $branch->location ?? null,
                    'image' => $branch->image ? asset('storage/' . $branch->image) : null,
                ] : null,
                'branch_manager' => $manager ? [
                    'id' => $manager->id,
                    'name' => $manager->name,
                    'image' => $manager->image_url ?? null,
                ] : null,
                // Item Details
                'item_title' => $branchItem->item_name,
                'item_logo' => $branchItem->item_logo_url,
                'quantity' => $quantity,
                'total_amount' => round($totalAmount, 2),
                // Available Quantity
                'available_quantity' => $availableQty,
                'available_quantity_label' => number_format($availableQty, 2) . ' ' . ($branchItem->item_unit ?? 'kg'),
                'availability_percentage' => min(100, round(($availableQty / $quantity) * 100, 1)),
                'quality' => $inventory->quality?->value,
                'expiry_date' => $inventory->earliest_expiry_date?->format('Y-m-d'),
                'cooling_status' => $inventory->cooling_status,
                'last_update' => $inventory->last_inventory_update?->format('Y-m-d H:i:s'),
                // Store Details
                'distance' => $distance,
                'distance_km' => $distance ? round($distance['distance_km'], 2) : null,
                'estimated_hours' => $distance ? round($distance['estimated_hours'], 1) : null,
                'response_rate' => $responseRate,
                'rating' => $rating,
            ];
        })->filter(); // Remove null values from filters
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
     */
    private function calculateDistance(?array $from, ?array $to): ?array
    {
        if (!$from || !$to) {
            return null;
        }

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

        // Estimate travel time (assuming average speed of 60 km/h for city, 80 km/h for highway)
        // Using 60 km/h as default
        $estimatedHours = $distanceKm / 60;

        return [
            'distance_km' => $distanceKm,
            'estimated_hours' => $estimatedHours,
        ];
    }

    /**
     * Get branch statistics for internal transfer (response rate and rating)
     */
    private function getBranchStatsForInternalTransfer(string $itemId, string $excludeBranchId): array
    {
        $sixMonthsAgo = now()->subMonths(6);

        // Get all internal transfer orders from/to these branches in last 6 months
        $orders = PurchaseOrder::where('order_type', OrderType::INTERNAL_TRANSFER)
            ->whereHas('items', function ($query) use ($itemId) {
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

            if (!isset($stats[$branchId])) {
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
            $respondedWithin24h = count(array_filter($data['response_times'], fn($t) => $t <= 24));
            $totalOrders = count($data['response_times']);
            $responseRate = $totalOrders > 0
                ? round(($respondedWithin24h / $totalOrders) * 100, 1)
                : null;

            // Rating: default to 4.5 if no rating system exists
            // This can be enhanced with actual rating system
            $rating = 4.5; // Default rating

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
        $threeMonthsAgo = now()->subMonths(3);

        $orderItems = PurchaseOrderItem::with(['purchaseOrder'])
            ->where('item_id', $itemId)
            ->whereHas('purchaseOrder', function ($query) use ($threeMonthsAgo) {
                $query->where('created_at', '>=', $threeMonthsAgo)
                    ->where('order_type', OrderType::INTERNAL_TRANSFER)
                    ->whereIn('status', [
                        OrderStatus::CONFIRMED,
                        OrderStatus::PARTIAL_CONFIRMATION,
                        OrderStatus::CLOSED,
                        OrderStatus::DELIVERED,
                    ]);
            })
            ->get();

        return $orderItems->isNotEmpty()
            ? round($orderItems->avg('unit_price'), 2)
            : 0;
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
            'fast' => $responseRate < 80,
            'normal' => $responseRate < 50 || $responseRate >= 80,
            'slow' => $responseRate >= 50,
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
}
