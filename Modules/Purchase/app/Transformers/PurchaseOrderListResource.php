<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Enums\OrderType;

class PurchaseOrderListResource extends JsonResource
{
    /**
     * Lightweight resource for order list (index) - only essential fields for performance
     */
    public function toArray($request): array
    {
        // Get source name based on order type
        $sourceName = $this->getSourceName();

        // Get request type from additional data or default to 'order'
        $requestType = $this->additional['request_type'] ?? 'order';

        // Get branch_id and inventory_map from additional data
        $branchId = $this->additional['branch_id'] ?? $this->branch_id;
        $inventoryMap = $this->additional['inventory_map'] ?? [];

        // Transform items with branch_id and inventory_map for inventory lookup
        $items = $this->whenLoaded('items', function () use ($branchId, $inventoryMap) {
            return PurchaseOrderItemResource::collection($this->items)->additional([
                'branch_id' => $branchId,
                'inventory_map' => $inventoryMap,
            ]);
        });

        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'order_type' => $this->order_type?->value,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'items' => $items,
            'source_name' => $sourceName,
            'request_type' => $requestType,
        ];
    }

    /**
     * Get source name based on order type
     */
    private function getSourceName(): ?string
    {
        if (!$this->order_type) {
            return null;
        }

        return match ($this->order_type) {
            OrderType::INTERNAL_TRANSFER => $this->relationLoaded('fromBranch')
                ? ($this->fromBranch?->name ?? null)
                : null,
            OrderType::DIRECT_SUPPLIER => $this->relationLoaded('supplier')
                ? ($this->supplier?->name ?? null)
                : null,
            OrderType::VIA_PURCHASING_OFFICER => null,
            default => null,
        };
    }
}
