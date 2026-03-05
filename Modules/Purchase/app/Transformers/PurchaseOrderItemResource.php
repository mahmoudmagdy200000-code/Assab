<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Models\BranchInventory;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray($request): array
    {
        // Get branch_id from additional data, request, order, or auth
        $branchId = $this->additional['branch_id']
            ?? $request->get('branch_id')
            ?? ($this->relationLoaded('purchaseOrder') ? $this->purchaseOrder->branch_id : null)
            ?? (auth()->check() ? auth()->user()->branch_id : null);

        // Get inventory data for current branch (store)
        // Use collection-level caching if available
        $inventory = null;
        if ($branchId && $this->item_id) {
            // Check if inventory map is available in additional data (batch loaded)
            $inventoryMap = $this->additional['inventory_map'] ?? null;
            if ($inventoryMap && isset($inventoryMap[$this->item_id])) {
                $inventory = $inventoryMap[$this->item_id];
            } else {
                // Fallback to individual query (less efficient)
                $inventory = BranchInventory::where('branch_id', $branchId)
                    ->where('item_id', $this->item_id)
                    ->first();
            }
        }

        // Calculate available in store based on new quantity if modified
        $availableInStore = null;
        if ($inventory) {
            $availableInStore = (float) $inventory->available_quantity;
            // If new_quantity is set, update available based on the change
            if ($this->new_quantity !== null && $this->original_quantity !== null) {
                $quantityDifference = $this->new_quantity - $this->original_quantity;
                $availableInStore = max(0, $availableInStore - $quantityDifference);
            }
        }

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,

            // Item Information
            'item_name' => $this->item_name,
            'item_logo' => $this->item_logo_url,
            'item_sku' => $this->item_sku,
            'category' => $this->category,
            'subcategory' => $this->subcategory,

            // Quantities
            'original_quantity' => $this->original_quantity ? (float) $this->original_quantity : (float) $this->quantity_ordered,
            'new_quantity' => $this->new_quantity ? (float) $this->new_quantity : (float) $this->quantity_ordered,
            'quantity_ordered' => (float) $this->quantity_ordered,
            'quantity_confirmed' => $this->quantity_confirmed ? (float) $this->quantity_confirmed : null,
            'quantity_received' => $this->quantity_received ? (float) $this->quantity_received : null,
            'unit_of_measurement' => $this->unit_of_measurement,

            // Balance Quantity: Automatically calculated as (Original Quantity - New Quantity)
            'balance_quantity' => $this->when(
                $this->original_quantity !== null && $this->new_quantity !== null,
                fn() => (float) max(0, $this->original_quantity - $this->new_quantity),
                fn() => 0.0
            ),

            // Available in Store: Updated based on new quantity
            'available_in_store' => $availableInStore,

            // Inventory data from BranchInventory
            'daily_consumption_quantity' => $inventory
                ? (float) $inventory->daily_consumption
                : ($this->daily_consumption ? (float) $this->daily_consumption : null),
            'weekend_forecast_quantity' => $inventory
                ? (float) $inventory->weekend_forecast
                : ($this->weekend_forecast ? (float) $this->weekend_forecast : null),
            'next_supply' => $inventory
                ? ($inventory->next_supply_date?->format('Y-m-d') ?? null)
                : ($this->next_supply_date?->format('Y-m-d') ?? null),

            // Pricing
            'unit_price' => (float) $this->unit_price,
            'total_price' => (float) $this->total_price,
            'discount' => (float) $this->discount,

            // Quality (fallback to latest receipt item when order item has variance but quality_received not synced)
            'quality_ordered' => $this->quality_ordered?->value,
            'quality_received' => $this->quality_received?->value ?? $this->getQualityReceivedFromReceipt(),

            // Transfer details (for internal transfers - source branch info)
            'available_in_source' => $this->available_in_source ? (float) $this->available_in_source : null,
            'remaining_balance' => $this->remaining_balance ? (float) $this->remaining_balance : null,
            'daily_consumption' => $this->daily_consumption ? (float) $this->daily_consumption : null,
            'weekend_forecast' => $this->weekend_forecast ? (float) $this->weekend_forecast : null,
            'next_supply_date' => $this->next_supply_date?->format('Y-m-d'),

            // Product info
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'temperature' => $this->temperature,
            'cooling_status' => $this->cooling_status,
            'inspection_notes' => $this->inspection_notes,

            // Transfer Ready (for Internal Transfer: only in Full Approved and Partial Approval)
            // Note: This requires purchaseOrder relationship to be loaded
            'transfer_ready' => $this->when(
                $this->relationLoaded('purchaseOrder') &&
                    $this->purchaseOrder &&
                    $this->purchaseOrder->order_type?->isTransfer() &&
                    in_array($this->purchaseOrder->status?->value, ['confirmed', 'partial_confirmation']),
                fn() => $this->cooling_status === true
            ),

            // Status
            'status' => $this->status?->value ?? 'pending',
            'status_label' => $this->status?->label() ?? 'Pending',
            'status_color' => $this->status?->color() ?? '#F59E0B',
            'quantity_variance' => $this->quantity_variance,
            'has_variance' => $this->has_variance,

            // Approval Information (if item needs approval, has approval data, or is canceled modification)
            'approval_type' => $this->when(
                $this->status?->needsApproval() || $this->approval_type || $this->status === \Modules\Purchase\Enums\OrderItemStatus::CANCELED_MODIFICATION,
                fn() => $this->approval_type
            ),
            'approval_data' => $this->when(
                $this->status?->needsApproval() || $this->approval_type || $this->status === \Modules\Purchase\Enums\OrderItemStatus::CANCELED_MODIFICATION,
                fn() => $this->approval_data
            ),
            'can_approve' => $this->when(
                $this->status?->needsApproval() || ($this->approval_type && !$this->status?->isCancelled()),
                fn() => true // Branch manager can approve
            ),
            'can_reject' => $this->when(
                $this->status?->needsApproval() || ($this->approval_type && !$this->status?->isCancelled()),
                fn() => true // Branch manager can reject
            ),

            // Modifications
            'modification_note' => $this->modification_note,
            'is_alternative' => $this->is_alternative,
            'is_gift' => $this->is_gift,
        ];
    }

    /**
     * Fallback: get quality_received from latest goods receipt item (for variance orders where sync may not have run).
     */
    private function getQualityReceivedFromReceipt(): ?string
    {
        if (!$this->relationLoaded('goodsReceiptItems') || $this->goodsReceiptItems->isEmpty()) {
            return null;
        }

        $latest = $this->goodsReceiptItems->sortByDesc('created_at')->first();

        return $latest?->quality_received?->value;
    }
}
