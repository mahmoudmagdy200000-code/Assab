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
                    // Return as float number (not formatted string)
                    $availableQuantity = $inventory?->available_quantity
                        ?? $item->available_in_source
                        ?? 0.0;
                    $availableQuantity = (float) $availableQuantity;

                    // Balance after = actual available (available - reserved) from inventory
                    // Fallback to remaining_balance from order item if inventory not found
                    $balanceAfter = $inventory
                        ? ($inventory->available_quantity - $inventory->reserved_quantity)
                        : ($item->remaining_balance ?? 0.0);
                    $balanceAfter = (float) $balanceAfter;

                    // Quality Grade - prefer inventory quality, then quality_received, then quality_ordered
                    // Return enum value (not label)
                    $qualityGrade = $inventory?->quality?->value
                        ?? $item->quality_received?->value
                        ?? $item->quality_ordered?->value
                        ?? 'standard';

                    // Expiry date - prefer inventory earliest_expiry_date, then item expiry_date
                    // Return as Y-m-d format (not formatted as "F Y")
                    $expiryDate = $inventory?->earliest_expiry_date
                        ?? $item->expiry_date;
                    $expiryDateFormatted = $expiryDate
                        ? Carbon::parse($expiryDate)->format('Y-m-d')
                        : null;

                    // Cooling status - prefer inventory cooling_status, then item cooling_status
                    // Return as boolean (not string)
                    if ($inventory?->cooling_status !== null) {
                        $coolingStatus = (bool) $inventory->cooling_status;
                    } elseif ($item->cooling_status !== null) {
                        $coolingStatus = (bool) $item->cooling_status;
                    } else {
                        $coolingStatus = false;
                    }

                    return [
                        'item_name' => $item->item_name,
                        'requested_qty' => (float) $item->quantity_ordered,
                        'available_in_branch_name' => $fromBranchNameOnly,
                        'available_in_quantity' => $availableQuantity,
                        'balance_after' => $balanceAfter,
                        'quality_grade' => $qualityGrade,
                        'expiry_date' => $expiryDateFormatted,
                        'cooling_status' => $coolingStatus,
                        'item_unit' => $item->unit_of_measurement,
                    ];
                });
            }),
        ];
    }
}
