<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
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
                return $this->items->map(function ($item) use ($fromBranchNameOnly) {
                    // Format expiry date as "Month Year" (e.g., "December 2025")
                    $expiryDateFormatted = $item->expiry_date
                        ? Carbon::parse($item->expiry_date)->format('F Y')
                        : null;

                    // Format cooling status
                    if ($item->cooling_status === true) {
                        $coolingStatus = 'Transfer Ready';
                    } elseif ($item->cooling_status === false) {
                        $coolingStatus = 'Not Ready';
                    } else {
                        $coolingStatus = null;
                    }

                    // Quality Grade - prefer quality_received (Excellent/Normal/Poor) over quality_ordered (Economy/Standard/Premium)
                    $qualityGrade = $item->quality_received?->label()
                        ?? $item->quality_ordered?->label()
                        ?? null;

                    return [
                        'item_name' => $item->item_name,
                        'requested_qty' => $this->formatQuantity($item->quantity_ordered, $item->unit_of_measurement),
                        'available_in_branch_name' => $fromBranchNameOnly,
                        'available_in_quantity' => $item->available_in_source
                            ? $this->formatQuantity($item->available_in_source, $item->unit_of_measurement)
                            : null,
                        'balance_after' => $item->remaining_balance
                            ? $this->formatQuantity($item->remaining_balance, $item->unit_of_measurement)
                            : null,
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
     *
     * @param float|int|null $quantity
     * @param string|null $unit
     * @return string|null
     */
    private function formatQuantity($quantity, ?string $unit): ?string
    {
        if ($quantity === null) {
            return null;
        }

        $formattedQuantity = (float) $quantity;
        // Format with 2 decimals, then remove trailing zeros
        $formattedQuantity = rtrim(rtrim(number_format($formattedQuantity, 2, '.', ''), '0'), '.');

        $unit = strtoupper($unit ?? '');
        return $formattedQuantity . ' ' . $unit;
    }
}
