<?php

namespace Modules\Purchase\Services\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;

/**
 * Interface for Purchase Order Service
 * 
 * Defines contract for purchase order operations
 */
interface PurchaseOrderServiceInterface
{
    /**
     * Get branch items with filters
     */
    public function getBranchItems(string $branchId, array $filters = [], int $perPage = null): LengthAwarePaginator;

    /**
     * Get purchase history with filters
     */
    public function getHistory(array $filters, int $perPage = null): LengthAwarePaginator;

    /**
     * Get orders with filters
     */
    public function getOrders(array $filters, int $perPage = null): LengthAwarePaginator;

    /**
     * Get pending orders with filters
     */
    public function getPendingOrders(array $filters, int $perPage = null): LengthAwarePaginator;

    /**
     * Get orders for receiving grouped by expected delivery date
     * Returns only orders with DELIVERED status
     */
    public function getOrdersForReceiving(array $filters, int $perPage = null): array;

    /**
     * Create a new purchase order
     */
    public function createOrder(array $data): PurchaseOrder;

    /**
     * Create multiple orders from different sources
     */
    public function createMultipleOrders(array $data, string $branchId, string $requestedBy, bool $isDraft = false): Collection;

    /**
     * Add item to order
     */
    public function addItem(PurchaseOrder $order, array $data): PurchaseOrderItem;

    /**
     * Update order items
     */
    public function updateItems(PurchaseOrder $order, array $items): void;

    /**
     * Submit order
     */
    public function submitOrder(PurchaseOrder $order): bool;

    /**
     * Get order details
     */
    public function getOrderDetails(string $orderId, ?string $branchId = null): ?PurchaseOrder;
}

