<?php

namespace Modules\Purchase\Repositories;

use Modules\Purchase\Models\PriceComparison;
use Carbon\Carbon;

class PriceComparisonRepository
{
    /**
     * Get latest price for item by order type
     */
    public function getLatestPrice(string $itemId, string $branchId, string $orderType): ?PriceComparison
    {
        return PriceComparison::where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->where('order_type', $orderType)
            ->with(['supplier', 'transferFromBranch'])
            ->orderBy('recorded_date', 'desc')
            ->first();
    }

    /**
     * Get price history
     */
    public function getPriceHistory(string $itemId, string $branchId, Carbon $fromDate)
    {
        return PriceComparison::where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->where('recorded_date', '>=', $fromDate)
            ->orderBy('recorded_date', 'asc')
            ->get();
    }

    /**
     * Record new price
     */
    public function recordPrice(array $data): PriceComparison
    {
        return PriceComparison::create($data);
    }
}
