<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptListResource extends JsonResource
{
    /**
     * Transform the resource into an array for list views
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function toArray($request): array
    {
        $orderType = $this->extractOrderType();
        $status = $this->determineStatus();

        return [
            'id' => $this->id,
            'items_count' => (int) ($this->items_count ?? 0),
            'type' => $orderType,
            'status' => $status,
            'date' => $this->getDate(),
        ];
    }

    /**
     * Extract order type from purchase order
     */
    private function extractOrderType(): ?string
    {
        try {
            if ($this->purchaseOrder && $this->purchaseOrder->order_type) {
                $orderTypeValue = $this->purchaseOrder->order_type;
                if ($orderTypeValue instanceof \BackedEnum) {
                    return $orderTypeValue->value;
                } elseif (is_string($orderTypeValue)) {
                    return $orderTypeValue;
                }
            }
        } catch (\Exception $e) {
            // If enum access fails, return null
        }

        return null;
    }

    /**
     * Determine status based on receipt and order status
     */
    private function determineStatus(): string
    {
        // For completed receipts, check if order was canceled
        if ($this->status === 'completed' || $this->status === 'closed') {
            try {
                if ($this->purchaseOrder) {
                    $orderStatus = $this->purchaseOrder->status;
                    if (
                        $orderStatus === \Modules\Purchase\Enums\OrderStatus::CANCELED ||
                        $orderStatus === \Modules\Purchase\Enums\OrderStatus::CANCELLED_BY_BRANCH ||
                        $orderStatus === \Modules\Purchase\Enums\OrderStatus::CANCELLED_BY_SUPPLIER
                    ) {
                        return 'canceled';
                    }
                }
            } catch (\Exception $e) {
                // If check fails, use receipt status
            }

            return 'closed';
        }

        // For draft receipts, always return 'draft'
        if ($this->status === 'draft') {
            return 'draft';
        }

        // Return the receipt status as fallback
        return $this->status ?? 'draft';
    }

    /**
     * Get the appropriate date based on context
     */
    private function getDate(): ?string
    {
        // For draft receipts, use updated_at, otherwise use created_at
        if ($this->status === 'draft') {
            return $this->updated_at?->format('Y-m-d H:i:s') ?? $this->created_at?->format('Y-m-d H:i:s');
        }

        return $this->created_at?->format('Y-m-d H:i:s');
    }
}
