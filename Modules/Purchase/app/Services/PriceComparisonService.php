<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Collection;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\PriceHistory;
use Modules\Purchase\Models\PurchaseSupplier;
use Modules\Purchase\Models\SupplierItem;

class PriceComparisonService
{
    /**
     * Compare prices for an item across all sources
     */
    public function comparePrices(string $itemId, float $quantity, ?string $branchId = null): array
    {
        // Get item name from BranchItem
        $item = BranchItem::find($itemId);
        $itemName = $item ? $item->item_name : null;

        $comparison = [
            'item_id' => $itemId,
            'item_name' => $itemName,
            'quantity' => $quantity,
            'sources' => [],
            'best_option' => null,
            'insights' => [],
            'price_trends' => [],
        ];

        // Direct Supplier prices
        $supplierPrices = $this->getSupplierPrices($itemId, $quantity);
        if ($supplierPrices->isNotEmpty()) {
            $comparison['sources']['direct_supplier'] = $supplierPrices->map(function ($item) use ($quantity) {
                return [
                    'supplier_id' => $item->supplier_id,
                    'supplier_name' => $item->supplier->name,
                    'supplier_status' => $item->supplier->status->value,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->unit_price * $quantity,
                    'delivery_hours' => $item->delivery_hours,
                    'delivery_days' => ceil($item->delivery_hours / 24),
                    'rating' => $item->rating ?? $item->supplier->rating,
                    'is_available' => $item->is_available,
                ];
            })->toArray();
        }

        // Via Purchasing Officer (average/estimated prices)
        $poPrices = $this->getPurchasingOfficerPrices($itemId);
        if ($poPrices) {
            $comparison['sources']['via_purchasing_officer'] = $poPrices;
        }

        // Internal Transfer options
        if ($branchId) {
            $transferOptions = $this->getInternalTransferOptions($itemId, $quantity, $branchId);
            if ($transferOptions->isNotEmpty()) {
                $comparison['sources']['internal_transfer'] = $transferOptions->toArray();
            }
        }

        // Get price trends
        $comparison['price_trends'] = $this->getPriceTrends($itemId);

        // Calculate best options
        $comparison['insights'] = $this->calculateInsights($comparison['sources']);
        $comparison['best_option'] = $this->determineBestOption($comparison['sources']);

        return $comparison;
    }

    /**
     * Get supplier prices for an item
     */
    private function getSupplierPrices(string $itemId, float $quantity): Collection
    {
        return SupplierItem::with('supplier')
            ->byItem($itemId)
            ->available()
            ->whereHas('supplier', fn($q) => $q->active())
            ->get()
            ->filter(fn($item) => $item->isWithinQuantityLimits($quantity));
    }

    /**
     * Get purchasing officer estimated prices
     */
    private function getPurchasingOfficerPrices(string $itemId): ?array
    {
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
            'delivery_days' => $history->delivery_days ?? 4, // Default 3-5 days
            'rating' => $history->rating,
            'processing_times' => [
                'standard' => ['min_days' => 3, 'max_days' => 5],
                'urgent' => ['min_days' => 1, 'max_days' => 2],
            ],
        ];
    }

    /**
     * Get internal transfer options
     */
    private function getInternalTransferOptions(string $itemId, float $quantity, string $excludeBranchId): Collection
    {
        return BranchInventory::with('branch')
            ->byItem($itemId)
            ->where('branch_id', '!=', $excludeBranchId)
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
     * Get price trends for last 3 months
     */
    public function getPriceTrends(string $itemId): array
    {
        $trends = [];
        $threeMonthsAgo = now()->subMonths(3);

        for ($i = 0; $i < 3; $i++) {
            $month = now()->subMonths($i);
            $periodMonth = $month->format('Y-m');
            $monthLabel = $month->format('M Y');

            $monthPrices = PriceHistory::byItem($itemId)
                ->byPeriod($periodMonth)
                ->get()
                ->groupBy('source_type');

            $trends[$periodMonth] = [
                'month' => $monthLabel,
                'direct_supplier' => $monthPrices->get(OrderType::DIRECT_SUPPLIER->value)?->avg('unit_price'),
                'via_purchasing_officer' => $monthPrices->get(OrderType::VIA_PURCHASING_OFFICER->value)?->avg('unit_price'),
                'internal_transfer' => 0,
            ];
        }

        return array_reverse($trends);
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
