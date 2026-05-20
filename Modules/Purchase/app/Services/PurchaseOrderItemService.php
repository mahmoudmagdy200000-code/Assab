<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Exceptions\PurchaseOrderException;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;

/**
 * PurchaseOrderItemService
 *
 * Handles item-level CRUD operations for purchase orders:
 * adding, updating, approving, rejecting, and cancelling items.
 * Extracted from PurchaseOrderService to reduce class size.
 */
class PurchaseOrderItemService
{
    private const ALLOWED_UNITS = ['kg', 'pk', 'unit', 'box', 'liter', 'piece'];

    private const DEFAULT_UNIT = 'kg';

    public function __construct(
        private readonly TimelineService $timelineService
    ) {}

    /**
     * Add item to order. Gets item details from Item model using item_id.
     */
    public function addItem(PurchaseOrder $order, array $data): PurchaseOrderItem
    {
        if (empty($data['item_id'])) {
            throw new \InvalidArgumentException('Item ID is required');
        }

        if (! isset($data['quantity']) || $data['quantity'] <= 0) {
            throw new \InvalidArgumentException('Quantity is required and must be greater than 0');
        }

        $item = Item::find($data['item_id']);
        $branchItem = BranchItem::where('branch_id', $order->branch_id)
            ->where('item_id', $data['item_id'])
            ->with('item')
            ->first();

        if (! $item) {
            throw new \InvalidArgumentException("Item with ID {$data['item_id']} not found");
        }

        $unitPrice = is_numeric($data['unit_price'] ?? null) ? (float) $data['unit_price'] : (float) ($branchItem?->price ?? 0);
        $quantity = is_numeric($data['quantity']) ? (float) $data['quantity'] : 0;
        $discount = isset($data['discount']) && is_numeric($data['discount']) ? (float) $data['discount'] : 0;
        $totalPrice = ($quantity * $unitPrice) - $discount;

        $itemLogo = is_array($item->logo) ? ($item->logo[0] ?? null) : $item->logo;
        $quality = $this->normalizeQualityLevel($data['quality'] ?? null);
        $unit = $this->resolveUnit($data['unit'] ?? null, $item->unit ?? null);

        $category = $item->category ?? ($branchItem?->item?->category ?? null);
        $subcategory = $item->subcategory ?? ($branchItem?->item?->subcategory ?? null);

        $itemStatus = ($order->status ?? null) === OrderStatus::DRAFT
            ? OrderItemStatus::DRAFT
            : OrderItemStatus::PENDING;

        try {
            return PurchaseOrderItem::create([
                'purchase_order_id' => $order->id,
                'item_id' => $item->id,
                'item_name' => $item->name ?? 'Unknown Item',
                'item_logo' => $itemLogo,
                'item_sku' => $item->code ?? null,
                'category' => $category,
                'subcategory' => $subcategory,
                'quantity_ordered' => $quantity,
                'original_quantity' => $quantity,
                'new_quantity' => $quantity,
                'unit_of_measurement' => $unit,
                'unit_price' => $unitPrice,
                'total_price' => max(0, $totalPrice),
                'discount' => $discount,
                'quality_ordered' => $quality,
                'status' => $itemStatus,
                'available_in_source' => $data['available_in_source'] ?? null,
                'daily_consumption' => $data['daily_consumption'] ?? null,
                'weekend_forecast' => $data['weekend_forecast'] ?? null,
                'next_supply_date' => $data['next_supply_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'cooling_status' => $data['cooling_status'] ?? null,
                'is_alternative' => $data['is_alternative'] ?? false,
                'is_gift' => $data['is_gift'] ?? false,
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating purchase order item', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
                'item_id' => $data['item_id'],
                'item_data' => $data,
            ]);
            throw PurchaseOrderException::failedToCreateItem($e->getMessage());
        }
    }

    /**
     * Update order items.
     */
    public function updateItems(PurchaseOrder $order, array $items): void
    {
        DB::transaction(function () use ($order, $items) {
            foreach ($items as $itemData) {
                if (! isset($itemData['id'])) {
                    continue;
                }
                $item = PurchaseOrderItem::find($itemData['id']);
                if ($item) {
                    $item->update([
                        'quantity_ordered' => $itemData['quantity'] ?? $item->quantity_ordered,
                        'unit_price' => $itemData['unit_price'] ?? $item->unit_price,
                        'quality_ordered' => $itemData['quality'] ?? $item->quality_ordered,
                    ]);
                    $item->calculateTotalPrice();
                }
            }

            $order->calculateTotals();
        });
    }

    /**
     * Approve item request (branch manager approves supplier's item change request).
     */
    public function approveItemRequest(PurchaseOrder $order, string $itemId, ?array $additionalData = null): bool
    {
        $item = $order->items()->where('item_id', $itemId)->first();

        if (! $item) {
            throw PurchaseOrderException::itemNotFound();
        }

        if (! $item->status->needsApproval() && ! in_array($item->status, [
            OrderItemStatus::PARTIAL_CONFIRMATION,
            OrderItemStatus::PARTIAL,
        ])) {
            throw new \InvalidArgumentException('Item must be in needs approval status to approve request');
        }

        return DB::transaction(function () use ($item, $additionalData, $order) {
            $item->approveRequest($additionalData);
            $item->save();

            $order->refresh();
            $order->load('items');
            $order->checkAndTransitionToConfirmed();
            $this->timelineService->logItemApproved($order, $item);

            return true;
        });
    }

    /**
     * Reject item request (branch manager rejects supplier's item change request).
     */
    public function rejectItemRequest(PurchaseOrder $order, string $itemId, ?string $reason = null): bool
    {
        $item = $order->items()->where('item_id', $itemId)->first();

        if (! $item) {
            throw PurchaseOrderException::itemNotFound();
        }

        if (! $item->status->needsApproval() && ! in_array($item->status, [
            OrderItemStatus::PARTIAL_CONFIRMATION,
            OrderItemStatus::PARTIAL,
        ])) {
            throw new \InvalidArgumentException('Item must be in needs approval status to reject request');
        }

        return DB::transaction(function () use ($item, $reason, $order) {
            $item->rejectRequest($reason);
            $item->save();

            $order->refresh();
            $order->load('items');
            $order->checkAndTransitionToConfirmed();
            $this->timelineService->logItemRejected($order, $item, $reason);

            return true;
        });
    }

    /**
     * Cancel item (branch manager cancels specific item).
     */
    public function cancelItem(PurchaseOrder $order, string $itemId, ?string $reason = null): bool
    {
        $item = $order->items()->where('item_id', $itemId)->first();

        if (! $item) {
            throw PurchaseOrderException::itemNotFound();
        }

        if ($item->status->isCancelled()) {
            throw new \InvalidArgumentException('Item is already cancelled');
        }

        return DB::transaction(function () use ($item, $reason, $order) {
            $item->cancelByBranch($reason);

            $order->refresh();
            $order->load('items');
            $order->checkAndTransitionToConfirmed();
            $this->timelineService->logItemRejected($order, $item, $reason ?? 'Item cancelled by branch manager');

            return true;
        });
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function resolveUnit(?string $dataUnit, ?string $itemUnit): string
    {
        if (! empty($dataUnit) && in_array($dataUnit, self::ALLOWED_UNITS, true)) {
            return $dataUnit;
        }
        if (! empty($itemUnit) && in_array($itemUnit, self::ALLOWED_UNITS, true)) {
            return $itemUnit;
        }

        return self::DEFAULT_UNIT;
    }

    private function normalizeQualityLevel(?string $qualityValue): ?QualityLevel
    {
        if (empty($qualityValue)) {
            return null;
        }

        $normalized = strtolower(trim($qualityValue));
        $allowedValues = ['economy', 'standard', 'premium'];

        if (! in_array($normalized, $allowedValues, true)) {
            Log::warning('Invalid quality level value provided, setting to null', [
                'provided_quality' => $qualityValue,
                'normalized_value' => $normalized,
            ]);

            return null;
        }

        try {
            return QualityLevel::from($normalized);
        } catch (\ValueError $e) {
            Log::warning('Failed to convert quality level to enum, setting to null', [
                'provided_quality' => $qualityValue,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
