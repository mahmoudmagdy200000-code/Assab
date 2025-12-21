<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\PurchaseOrder;

/**
 * Service for creating purchase orders
 * 
 * Handles creation of different order types
 */
class OrderCreationService
{
    public function __construct(
        private readonly PurchaseOrderService $orderService
    ) {}

    /**
     * Create internal transfer order
     */
    public function createInternalTransferOrder(
        array $branchData,
        string $branchId,
        string $requestedBy,
        bool $isDraft,
        int $index
    ): PurchaseOrder {
        $this->validateBranchData($branchData, $index);

        $orderData = [
            'order_type' => OrderType::INTERNAL_TRANSFER,
            'status' => $isDraft ? OrderStatus::DRAFT : OrderStatus::PENDING,
            'branch_id' => $branchId,
            'requested_by' => $requestedBy,
            'from_branch_id' => $branchData['branch_id'],
            'to_branch_id' => $branchId,
            'priority' => $branchData['priority'] ?? 'normal',
            'message' => $branchData['justification'] ?? null,
            'items' => $branchData['items'] ?? [],
        ];

        return $this->orderService->createOrder($orderData);
    }

    /**
     * Create direct supplier order
     */
    public function createDirectSupplierOrder(
        array $supplierData,
        string $branchId,
        string $requestedBy,
        bool $isDraft,
        int $index
    ): PurchaseOrder {
        $this->validateSupplierData($supplierData, $index);

        $qualityLevel = $this->normalizeQualityLevel($supplierData['quality_level'] ?? null);

        $orderData = [
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => $isDraft ? OrderStatus::DRAFT : OrderStatus::PENDING,
            'branch_id' => $branchId,
            'requested_by' => $requestedBy,
            'supplier_id' => $supplierData['supplier_id'],
            'quality_level' => $qualityLevel,
            'notification_channels' => $supplierData['notification_channels'] ?? [],
            'message' => $supplierData['message'] ?? null,
            'items' => $supplierData['items'] ?? [],
        ];

        return $this->orderService->createOrder($orderData);
    }

    /**
     * Create purchasing officer order
     */
    public function createPurchasingOfficerOrder(
        array $officerData,
        string $branchId,
        string $requestedBy,
        bool $isDraft,
        int $index
    ): PurchaseOrder {
        $this->validateOfficerData($officerData, $index);

        $items = $officerData['items'] ?? [];
        $firstItem = !empty($items) ? $items[0] : [];

        $qualityLevelRaw = $firstItem['quality'] ?? $officerData['quality_level'] ?? 'standard';
        $qualityLevel = $this->normalizeQualityLevel($qualityLevelRaw);

        $orderData = [
            'order_type' => OrderType::VIA_PURCHASING_OFFICER,
            'status' => $isDraft ? OrderStatus::DRAFT : OrderStatus::PENDING,
            'branch_id' => $branchId,
            'requested_by' => $requestedBy,
            'quality_level' => $qualityLevel,
            'processing_time' => $officerData['processing_time'] ?? 'standard',
            'preferred_delivery_date' => $firstItem['preferred_delivery_date'] ?? $officerData['preferred_delivery_date'] ?? null,
            'latest_delivery_date' => $firstItem['latest_delivery_date'] ?? $officerData['latest_delivery_date'] ?? null,
            'special_instructions' => $firstItem['special_instructions'] ?? $officerData['special_instructions'] ?? null,
            'message' => $officerData['message'] ?? null,
            'items' => $items,
        ];

        return $this->orderService->createOrder($orderData);
    }

    /**
     * Validate branch data
     */
    private function validateBranchData(array $branchData, int $index): void
    {
        if (empty($branchData['branch_id'])) {
            throw new \InvalidArgumentException("Branch ID is required for branch entry at index {$index}");
        }

        if (empty($branchData['items']) || !is_array($branchData['items']) || count($branchData['items']) === 0) {
            throw new \InvalidArgumentException("At least one item is required for branch entry at index {$index}");
        }
    }

    /**
     * Validate supplier data
     */
    private function validateSupplierData(array $supplierData, int $index): void
    {
        if (empty($supplierData['supplier_id'])) {
            throw new \InvalidArgumentException("Supplier ID is required for direct supplier order at index {$index}");
        }

        if (empty($supplierData['items']) || !is_array($supplierData['items']) || count($supplierData['items']) === 0) {
            throw new \InvalidArgumentException("At least one item is required for direct supplier order at index {$index}");
        }

        if (empty($supplierData['notification_channels']) || !is_array($supplierData['notification_channels']) || count($supplierData['notification_channels']) === 0) {
            throw new \InvalidArgumentException("At least one notification channel is required for direct supplier order at index {$index}");
        }
    }

    /**
     * Validate officer data
     */
    private function validateOfficerData(array $officerData, int $index): void
    {
        $items = $officerData['items'] ?? [];
        if (empty($items) || !is_array($items) || count($items) === 0) {
            throw new \InvalidArgumentException("At least one item is required for purchasing officer order at index {$index}");
        }
    }

    /**
     * Normalize quality level
     */
    private function normalizeQualityLevel(?string $qualityLevel): string
    {
        if (empty($qualityLevel)) {
            return 'standard';
        }

        $normalized = strtolower(trim($qualityLevel));
        $validLevels = ['standard', 'premium', 'economy'];

        return in_array($normalized, $validLevels) ? $normalized : 'standard';
    }
}

