<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Enums\ModificationType;

class ModificationDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Returns different structure based on modification type:
     * - new_quantity: Quantity modification details
     * - need_time: Delivery time modification details
     * - alternative_product: Alternative product proposal details
     */
    public function toArray($request): array
    {
        // Get modification type from approval_type or determine from item data
        $modificationType = $this->getModificationType();

        $status = $this->getModificationStatus();

        $baseData = [
            'item_id' => $this->id,
            'type' => $modificationType->value,
            'type_label' => $modificationType->label(),
            'modified_at' => $this->updated_at?->timestamp,
            'status' => $status,
            'status_message' => $this->getStatusMessage($status),
        ];

        // Add type-specific data
        return match ($modificationType) {
            ModificationType::NEW_QUANTITY => $this->getNewQuantityData($baseData),
            ModificationType::NEED_TIME => $this->getNeedTimeData($baseData),
            ModificationType::ALTERNATIVE_PRODUCT => $this->getAlternativeProductData($baseData),
        };
    }

    /**
     * Get modification type from item data
     */
    private function getModificationType(): ModificationType
    {
        // Check approval_type first
        if ($this->approval_type) {
            $type = ModificationType::fromApprovalType($this->approval_type);
            if ($type) {
                return $type;
            }
        }

        // Fallback: determine from item data
        if ($this->is_alternative) {
            return ModificationType::ALTERNATIVE_PRODUCT;
        }

        if ($this->original_quantity !== null && $this->new_quantity !== null) {
            return ModificationType::NEW_QUANTITY;
        }

        // Check if order has delivery time change
        if (
            $this->relationLoaded('purchaseOrder') &&
            $this->purchaseOrder &&
            $this->purchaseOrder->expected_delivery_at &&
            $this->purchaseOrder->preferred_delivery_date
        ) {
            return ModificationType::NEED_TIME;
        }

        // Default to new quantity if quantity fields are set
        return ModificationType::NEW_QUANTITY;
    }

    /**
     * Get modification status
     */
    private function getModificationStatus(): string
    {
        if ($this->status->needsApproval()) {
            return 'pending_approval';
        }

        if ($this->status->isRejected()) {
            return 'rejected';
        }

        if ($this->status->value === 'confirmed') {
            return 'approved';
        }

        return 'pending';
    }

    /**
     * Get status message based on status
     */
    private function getStatusMessage(string $status): string
    {
        return match ($status) {
            'pending_approval' => 'Supplier modified this item, pending your approval.',
            'approved' => 'Modifications approved by you. Item has been updated to new suggestions.',
            'rejected' => 'Modifications rejected by you.',
            default => 'Pending approval.',
        };
    }

    /**
     * Get data for new quantity modification
     */
    private function getNewQuantityData(array $baseData): array
    {
        $order = $this->relationLoaded('purchaseOrder') ? $this->purchaseOrder : $this->purchaseOrder()->first();

        return array_merge($baseData, [
            'original_order' => [
                'id' => $this->id,
                'item_id' => $this->item_id,
                'item_name' => $this->item_name,
                'item_logo' => $this->item_logo_url,
                'requested_qty' => (float) ($this->original_quantity ?? $this->quantity_ordered),
                'unit' => $this->unit_of_measurement,
                'quality' => $this->quality_ordered?->value,
                'price' => (float) $this->unit_price,
                'price_per_unit' => (float) $this->unit_price . ' / ' . $this->unit_of_measurement,
                'total_price' => (float) $this->total_price,
                'delivery_date' => $order->preferred_delivery_date?->timestamp,
            ],
            'supplier_proposal' => [
                'type' => 'New Quantity',
                'proposed_qty' => (float) ($this->new_quantity ?? $this->quantity_ordered),
                'shortage' => (float) max(0, ($this->original_quantity ?? $this->quantity_ordered) - ($this->new_quantity ?? $this->quantity_ordered)),
                'quality' => $this->quality_ordered?->value,
                'price' => (float) $this->unit_price,
                'price_per_unit' => (float) $this->unit_price . ' / ' . $this->unit_of_measurement,
                'total_price' => (float) (($this->new_quantity ?? $this->quantity_ordered) * $this->unit_price),
            ],
            'modification_notes' => $this->note ?? $this->reason,
        ]);
    }

    /**
     * Get data for need time modification
     */
    private function getNeedTimeData(array $baseData): array
    {
        $order = $this->relationLoaded('purchaseOrder') ? $this->purchaseOrder : $this->purchaseOrder()->first();
        $approvalData = $this->approval_data ?? [];

        $originalDeliveryDate = $order->preferred_delivery_date;
        $newDeliveryDate = $order->expected_delivery_at ?? ($approvalData['requested_delivery_time'] ?? null);

        if (is_string($newDeliveryDate)) {
            $newDeliveryDate = \Carbon\Carbon::parse($newDeliveryDate);
        }

        $daysDifference = $originalDeliveryDate && $newDeliveryDate
            ? $originalDeliveryDate->diffInDays($newDeliveryDate)
            : 0;

        return array_merge($baseData, [
            'original_order' => [
                'id' => $this->id,
                'item_id' => $this->item_id,
                'item_name' => $this->item_name,
                'item_logo' => $this->item_logo_url,
                'requested_qty' => (float) $this->quantity_ordered,
                'unit' => $this->unit_of_measurement,
                'quality' => $this->quality_ordered?->value,
                'price' => (float) $this->unit_price,
                'price_per_unit' => (float) $this->unit_price . ' / ' . $this->unit_of_measurement,
                'total_price' => (float) $this->total_price,
                'delivery_date' => $originalDeliveryDate?->format('F d, Y'),
            ],
            'supplier_proposal' => [
                'type' => 'Low Stock — Need Time',
                'delivery_details' => [
                    'qty_to_deliver' => (float) $this->quantity_ordered . ' (Full)',
                    'is_full' => true,
                    'new_delivery_date' => $newDeliveryDate?->timestamp,
                    'days_difference' => $daysDifference,
                    'days_difference_label' => $daysDifference > 0 ? "+{$daysDifference} Days" : null,
                ],
            ],
            'modification_notes' => $approvalData['note'] ?? $order->message ?? $this->reason,
        ]);
    }

    /**
     * Get data for alternative product modification
     */
    private function getAlternativeProductData(array $baseData): array
    {
        $order = $this->relationLoaded('purchaseOrder') ? $this->purchaseOrder : $this->purchaseOrder()->first();
        $approvalData = $this->approval_data ?? [];

        $originalItemId = $approvalData['original_item_id'] ?? $this->item_id;
        $alternativeItemId = $approvalData['alternative_item_id'] ?? $this->item_id;
        $alternativeItemName = $approvalData['alternative_item_name'] ?? $this->item_name;
        $alternativePrice = $approvalData['alternative_price'] ?? $this->unit_price;

        return array_merge($baseData, [
            'original_order' => [
                'id' => $this->id,
                'item_id' => $originalItemId,
                'item_name' => $approvalData['original_item_name'] ?? $this->item_name,
                'item_logo' => $this->item_logo_url,
                'requested_qty' => (float) $this->quantity_ordered,
                'unit' => $this->unit_of_measurement,
                'quality' => $this->quality_ordered?->value,
                'price' => (float) $this->unit_price,
                'price_per_unit' => (float) $this->unit_price . ' / ' . $this->unit_of_measurement,
                'total_price' => (float) $this->total_price,
                'delivery_date' => $order->preferred_delivery_date?->timestamp,
            ],
            'supplier_proposal' => [
                'type' => 'Propose Alternative Product',
                'alternative_product' => [
                    'item_id' => $alternativeItemId,
                    'item_name' => $alternativeItemName,
                    'item_logo' => $this->item_logo_url,
                    'proposed_qty' => (float) $this->quantity_ordered,
                    'quality' => $this->quality_ordered?->value,
                    'price' => (float) $alternativePrice,
                    'price_per_unit' => (float) $alternativePrice . ' / ' . $this->unit_of_measurement,
                    'total_price' => (float) ($this->quantity_ordered * $alternativePrice),
                ],
            ],
            'modified_total' => [
                'original_total' => (float) $this->total_price,
                'modified_total' => (float) ($this->quantity_ordered * $alternativePrice),
                'difference' => (float) ($this->total_price - ($this->quantity_ordered * $alternativePrice)),
            ],
            'modification_notes' => $approvalData['note'] ?? $this->reason,
        ]);
    }
}
