<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class TransferItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        $item = $this->resource['item'];
        $fromInventory = $this->resource['from_inventory'];
        $toInventory = $this->resource['to_inventory'];
        $transportDetails = $this->resource['transport_details'];

        // Get item logo URL (Item model has logo attribute)
        $itemLogo = null;
        $logo = $item->logo ?? null;

        if ($logo) {
            if (is_array($logo)) {
                $logo = $logo[0] ?? null;
            }

            if ($logo) {
                $itemLogo = str_starts_with($logo, 'http')
                    ? $logo
                    : asset('storage/' . $logo);
            }
        }

        // Calculate available quantity in transferring branch
        // Use actual_available accessor (same as getBranchesWithStock uses)
        $availableQuantity = $fromInventory
            ? (float) $fromInventory->actual_available
            : 0.0;

        // Calculate remaining balance in transferring branch (same as available for now)
        $remainingBalance = $availableQuantity;

        // Get quality from inventory
        $quality = $fromInventory && $fromInventory->quality
            ? $fromInventory->quality->value
            : null;

        $qualityLabel = $fromInventory && $fromInventory->quality
            ? $fromInventory->quality->label()
            : null;

        // Determine recommended temperature based on cooling status
        $temperature = null;
        if ($fromInventory && $fromInventory->cooling_status) {
            $temperature = '2-8°C'; // Refrigerated
        } elseif ($fromInventory && $fromInventory->cooling_status === false) {
            $temperature = 'Room Temperature';
        }

        // Use Item.id (consistent with getBranches response)
        $itemId = $this->resource['requested_item_id'] ?? $item->id;

        return [
            // Item Information
            'item_id' => $itemId, // Item.id (consistent with getBranches)
            'item_name' => $item->name ?? null,
            'item_logo' => $itemLogo,
            'item_code' => $item->code ?? null,
            'item_unit' => $item->unit ?? 'kg',

            // Editable Fields (defaults to inventory values, but can be edited by client)
            'quantity' => $availableQuantity, // Default to available, but editable
            'quality' => $quality, // Default to inventory quality, but editable
            'quality_label' => $qualityLabel,

            // Available in Transferring Branch
            'available_in_transferring_branch' => [
                'quantity' => $fromInventory ? (float) $fromInventory->available_quantity : 0.0,
                'reserved_quantity' => $fromInventory ? (float) $fromInventory->reserved_quantity : 0.0,
                'actual_available' => $availableQuantity,
                'quality' => $quality,
                'quality_label' => $qualityLabel,
            ],

            // Remaining Balance in Transferring Branch
            'remaining_balance_in_transferring_branch' => [
                'quantity' => $remainingBalance,
                'quality' => $quality,
                'quality_label' => $qualityLabel,
            ],

            // Expiry Date
            'expiry_date' => $fromInventory?->earliest_expiry_date?->format('Y-m-d'),

            // Cooling Status
            'cooling_status' => $fromInventory?->cooling_status ?? false,
            'transfer_ready' => $fromInventory && $availableQuantity > 0,

            // Transport Details
            'transport' => [
                'method' => $transportDetails['method'] ?? 'Vehicle (Free)',
                'estimated_time_hours' => $transportDetails['estimated_time_hours'] ?? 0,
                'driver' => $transportDetails['driver'] ?? null,
                'temperature' => $temperature ?? $transportDetails['recommended_temperature'] ?? null,
                'distance_km' => $transportDetails['distance_km'] ?? 0,
                'cost' => $transportDetails['cost'] ?? 'Free',
                'from_branch' => $transportDetails['from_branch'] ?? null,
                'to_branch' => $transportDetails['to_branch'] ?? null,
            ],
        ];
    }
}
