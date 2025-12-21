<?php

namespace Modules\Purchase\Services\Contracts;

use Illuminate\Support\Collection;

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
     * Get suppliers for an item
     */
    public function getSuppliers(string $itemId, array $filters = []): Collection;

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
}

