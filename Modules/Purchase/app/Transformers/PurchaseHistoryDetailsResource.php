<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Modules\Purchase\Models\BranchInventory;
use Carbon\Carbon;

class PurchaseHistoryDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Returns only the essential fields for purchase history details view:
     * - Request Summary (Request No, Type, From, Requested By, Priority, Request Date, Justification, Status)
     * - Product Details (Requested Qty, Available In, Balance After, Quality Grade, Expiry Date, Cooling Status)
     */
    public function toArray($request): array
    {
        // Format branch location and name for "From" field
        $fromBranchFullName = $this->whenLoaded('fromBranch', function () {
            if ($this->fromBranch->location && $this->fromBranch->name) {
                return "{$this->fromBranch->location} - {$this->fromBranch->name}";
            }
            return $this->fromBranch->name ?? null;
        });

        // Get branch name only (for product details)
        $fromBranchNameOnly = $this->whenLoaded('fromBranch', function () {
            return $this->fromBranch->name ?? null;
        });

        // Format request date
        $requestDate = $this->created_at
            ? Carbon::parse($this->created_at)->format('F j, Y')
            : null;

        // Format requested by name (with "Me" if current user)
        $requestedByName = $this->whenLoaded('requestedBy', function () {
            $name = $this->requestedBy->name ?? '';
            if (Auth::check() && Auth::id() === $this->requestedBy->id) {
                $name .= ' (Me)';
            }
            return $name;
        });

        return [
            // Request Summary
            'request_summary' => [
                'request_no' => $this->order_number,
                'type' => $this->order_type_label,
                'from' => $fromBranchFullName,
                'requested_by' => $requestedByName,
                'priority' => $this->priority?->value,
                'request_date' => $requestDate,
                'justification' => $this->message,
                'status' => $this->status_label,
            ],

            // Product Details
            'product_details' => $this->whenLoaded('items', function () use ($fromBranchNameOnly) {
                // Get from_branch_id for inventory lookup
                $fromBranchId = $this->from_branch_id;

                // Eager load all inventory records for all items at once (performance optimization)
                $inventories = collect();
                if ($fromBranchId && $this->items->isNotEmpty()) {
                    $itemIds = $this->items->pluck('item_id')->filter()->unique()->toArray();
                    if (!empty($itemIds)) {
                        $inventories = BranchInventory::where('branch_id', $fromBranchId)
                            ->whereIn('item_id', $itemIds)
                            ->get()
                            ->keyBy('item_id');
                    }
                }

                return $this->items->map(function ($item) use ($fromBranchNameOnly, $inventories) {
                    // Get inventory data from preloaded collection
                    $inventory = $item->item_id ? ($inventories->get($item->item_id) ?? null) : null;

                    // Available quantity from inventory (fallback to order item if not in inventory)
                    $availableQuantity = $inventory?->available_quantity
                        ?? $item->available_in_source
                        ?? 0;

                    // Balance after = actual available (available - reserved) from inventory
                    // Fallback to remaining_balance from order item if inventory not found
                    $balanceAfter = $inventory
                        ? ($inventory->available_quantity - $inventory->reserved_quantity)
                        : ($item->remaining_balance ?? 0);

                    // Quality Grade - prefer inventory quality, then quality_received, then quality_ordered
                    // Default to "Standard" if not available
                    $qualityGrade = $inventory?->quality?->label()
                        ?? $item->quality_received?->label()
                        ?? $item->quality_ordered?->label()
                        ?? 'standard';

                    // Expiry date - prefer inventory earliest_expiry_date, then item expiry_date
                    // Default to "N/A" if not available
                    $expiryDate = $inventory?->earliest_expiry_date
                        ?? $item->expiry_date;
                    $expiryDateFormatted = $expiryDate
                        ? Carbon::parse($expiryDate)->format('F Y')
                        : 'N/A';

                    // Cooling status - prefer inventory cooling_status, then item cooling_status
                    // Default to "Not Ready" if not available
                    if ($inventory?->cooling_status !== null) {
                        $coolingStatusValue = $inventory->cooling_status;
                    } elseif ($item->cooling_status !== null) {
                        $coolingStatusValue = $item->cooling_status;
                    } else {
                        $coolingStatusValue = false;
                    }
                    $coolingStatus = $coolingStatusValue === true
                        ? 'Transfer Ready'
                        : 'Not Ready';

                    return [
                        'item_name' => $item->item_name,
                        'requested_qty' => $this->formatQuantity($item->quantity_ordered, $item->unit_of_measurement),
                        'available_in_branch_name' => $fromBranchNameOnly,
                        'available_in_quantity' => $this->formatQuantity($availableQuantity, $item->unit_of_measurement),
                        'balance_after' => $this->formatQuantity($balanceAfter, $item->unit_of_measurement),
                        'quality_grade' => $qualityGrade,
                        'expiry_date' => $expiryDateFormatted,
                        'cooling_status' => $coolingStatus,
                    ];
                });
            }),
        ];
    }

    /**
     * Format quantity with unit of measurement
     * Removes unnecessary decimal places (e.g., 200.00 becomes 200)
     * Returns "0 UNIT" if quantity is null or 0
     *
     * @param float|int|null $quantity
     * @param string|null $unit
     * @return string
     */
    private function formatQuantity($quantity, ?string $unit): string
    {
        $quantity = $quantity ?? 0;
        $formattedQuantity = (float) $quantity;

        // Format with 2 decimals, then remove trailing zeros
        $formattedQuantity = rtrim(rtrim(number_format($formattedQuantity, 2, '.', ''), '0'), '.');

        // Handle case where all decimals were removed (e.g., "200." becomes empty)
        if ($formattedQuantity === '') {
            $formattedQuantity = '0';
        }

        $unit = strtoupper($unit ?? '');
        return $formattedQuantity . ' ' . $unit;
    }
}
