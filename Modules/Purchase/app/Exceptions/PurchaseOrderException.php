<?php

namespace Modules\Purchase\Exceptions;

use RuntimeException;

class PurchaseOrderException extends RuntimeException
{
    public static function itemNotFound(): self
    {
        return new self('Item not found in order');
    }

    public static function orderNotInDelayedStatus(): self
    {
        return new self('Order is not in delayed status');
    }

    public static function noDelayedItemsFound(): self
    {
        return new self('No delayed items found in order');
    }

    public static function supplierIdRequired(): self
    {
        return new self('Supplier ID is required for direct supplier orders');
    }

    public static function requestedByRequired(): self
    {
        return new self('Requested by (Branch Manager ID) or sourceable is required');
    }

    public static function fromBranchIdRequired(): self
    {
        return new self('From branch ID is required for internal transfer orders');
    }

    public static function failedToCreateItem(string $message): self
    {
        return new self("Failed to create order item: {$message}");
    }

    public static function failedToCreateOrder(int $index, string $message): self
    {
        return new self("Failed to create order at index {$index}: {$message}");
    }

    public static function notDecidable(string $status): self
    {
        return new self("Order cannot be decided in its current status: {$status}");
    }

    public static function decisionNotApplied(string $status): self
    {
        return new self("Decision could not be applied; order remains in status: {$status}");
    }

    public static function itemsNotInOrder(array $ids): self
    {
        return new self('Order items do not belong to this order: '.implode(', ', $ids));
    }

    public static function nothingToConsolidate(): self
    {
        return new self('No consolidatable orders for this supplier');
    }

    public static function supplierMismatch(string $orderNumber): self
    {
        return new self("Order {$orderNumber} belongs to a different supplier");
    }
}
