<?php

namespace Modules\Purchase\Services\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Purchase\Models\SavedPriceComparison;

/**
 * Interface for Price Comparison Service
 *
 * Defines contract for price comparison operations
 */
interface PriceComparisonServiceInterface
{
    /**
     * Compare prices for an item across all sources
     */
    public function comparePrices(string $itemId, ?float $quantity = null, ?string $excludeBranchId = null): array;

    /**
     * Get purchasing officer prices
     */
    public function getPurchasingOfficerPrices(string $itemId): ?array;

    /**
     * Get suppliers for an item, or — when no item is given — every supplier the
     * branch may order from ("All Suppliers" in the app's source picker).
     */
    public function getSuppliers(?string $itemId, array $filters = [], ?string $branchId = null): Collection;

    /**
     * Get branches with stock for internal transfer
     */
    public function getBranchesWithStock(string $itemId, float $quantity, string $excludeBranchId, array $filters = []): Collection;

    /**
     * Get price trends
     */
    public function getPriceTrends(string $itemId): array;

    /**
     * Get internal transfer options
     */
    public function getInternalTransferOptions(string $itemId, float $quantity, ?string $excludeBranchId = null): Collection;

    /**
     * Save a price comparison snapshot for a branch
     */
    public function saveComparison(
        string $itemId,
        ?float $quantity,
        string $branchId,
        string $userId,
        ?string $note = null
    ): SavedPriceComparison;

    /**
     * Get a paginated list of saved comparisons for a branch
     */
    public function getSavedComparisons(string $branchId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Get a single saved comparison scoped to a branch
     */
    public function getSavedComparison(string $id, string $branchId): ?SavedPriceComparison;

    /**
     * Delete a saved comparison scoped to a branch
     */
    public function deleteSavedComparison(string $id, string $branchId): bool;
}
