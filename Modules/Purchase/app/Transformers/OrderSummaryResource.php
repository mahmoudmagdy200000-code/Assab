<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Services\PriceComparisonService;

class OrderSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        // Return different structure based on order type
        if ($this->order_type === OrderType::DIRECT_SUPPLIER) {
            return $this->getDirectSupplierResponse();
        } elseif ($this->order_type === OrderType::VIA_PURCHASING_OFFICER) {
            return $this->getViaPurchasingOfficerResponse();
        } elseif ($this->order_type === OrderType::INTERNAL_TRANSFER) {
            return $this->getInternalTransferResponse();
        }

        // Fallback for unknown types
        return [
            'order_number' => $this->order_number,
            'order_type' => $this->order_type?->value,
            'order_type_label' => $this->order_type_label,
        ];
    }

    /**
     * Get Direct Supplier Order response structure
     */
    private function getDirectSupplierResponse(): array
    {
        return [
            'request_summary' => [
                'supplier' => $this->whenLoaded('supplier', function () {
                    if (!$this->supplier) {
                        return null;
                    }
                    return [
                        'id' => $this->supplier->id,
                        'name' => $this->supplier->name,
                        'image' => $this->supplier->image_url,
                        'status' => $this->supplier->status?->value ?? null, // online, away, offline
                    ];
                }),
                'order_number' => $this->order_number,
                'type' => 'Direct Supplier Order',
                'from' => [
                    'id' => $this->branch?->id,
                    'branch_name' => $this->branch?->name,
                    'branch_location' => $this->branch?->location,
                ],
                'total_amount' => (float) $this->total_amount,
                'message' => $this->message,
            ],
            'contact_modes' => $this->notification_channels ?? ($this->supplier?->contact_methods ?? []),
            'items' => $this->getDirectSupplierItems(),
        ];
    }

    /**
     * Get Direct Supplier items list
     */
    private function getDirectSupplierItems(): array
    {
        return $this->items->map(function ($item) {
            return [
                'id' => $item->id,
                'item_name' => $item->item_name,
                'item_logo' => $item->item_logo_url,
                'quantity' => (float) $item->quantity_ordered,
                'quality' => $item->quality_ordered?->value,
                'price_rate' => (float) $item->unit_price,
                'total_price_per_item' => (float) $item->total_price, // Price Rate × Quantity
            ];
        })->toArray();
    }

    /**
     * Get Via Purchasing Officer response structure
     */
    private function getViaPurchasingOfficerResponse(): array
    {
        return [
            'request_summary' => [
                'order_number' => $this->order_number,
                'type' => 'Via Purchasing Officer',
                'from' => [
                    'id' => $this->branch?->id,
                    'branch_name' => $this->branch?->name,
                    'branch_location' => $this->branch?->location,
                ],
                'requested_by' => $this->requestedBy?->name ?? 'Me',
                'requested_date' => $this->created_at?->format('Y-m-d H:i:s'),
                'message' => $this->message,
            ],
            'items' => $this->getViaPurchasingOfficerItems(),
            'price_comparison' => $this->calculatePriceComparison(),
        ];
    }

    /**
     * Get Via Purchasing Officer items list
     */
    private function getViaPurchasingOfficerItems(): array
    {
        return $this->items->map(function ($item) {
            return [
                'id' => $item->id,
                'item_name' => $item->item_name,
                'item_logo' => $item->item_logo_url,
                'quantity' => (float) $item->quantity_ordered,
                'quality' => $item->quality_ordered?->value,
                'preferred_delivery_date' => $this->preferred_delivery_date?->format('Y-m-d'),
                'latest_delivery_date' => $this->latest_delivery_date?->format('Y-m-d'),
                'special_instructions' => $this->special_instructions,
            ];
        })->toArray();
    }

    /**
     * Get Internal Transfer response structure
     */
    private function getInternalTransferResponse(): array
    {
        return [
            'priority' => $this->priority?->value, // high, normal
            'request_summary' => [
                'order_number' => $this->order_number,
                'type' => 'Internal Transfer (No Cost)',
                'from' => [
                    'id' => $this->fromBranch?->id,
                    'branch_name' => $this->fromBranch?->name,
                    'branch_location' => $this->fromBranch?->location,
                ],
                'requested_by' => $this->requestedBy?->name ?? 'Me',
                'requested_date' => $this->created_at?->format('Y-m-d H:i:s'),
            ],
            'items' => $this->getInternalTransferItems(),
            'transport_details' => [
                'method' => $this->transport_method ?? 'Vehicle (Free)',
                'estimated_time' => $this->estimated_transport_hours ? round($this->estimated_transport_hours, 1) . ' Hours' : null,
                'driver' => $this->driver_name ?? 'Auto-assigned',
                'temperature' => $this->temperature,
            ],
        ];
    }

    /**
     * Get Internal Transfer items list
     */
    private function getInternalTransferItems(): array
    {
        return $this->items->map(function ($item) {
            return [
                'id' => $item->id,
                'item_name' => $item->item_name,
                'item_logo' => $item->item_logo_url,
                'quantity' => (float) $item->quantity_ordered,
                'quality' => $item->quality_ordered?->value,
                'available_in_transferring_branch' => $item->available_in_source ? [
                    'quantity' => (float) $item->available_in_source,
                    'quality' => $item->quality_ordered?->value,
                ] : null,
                'remaining_balance_in_transferring_branch' => $item->remaining_balance ? [
                    'quantity' => (float) $item->remaining_balance,
                    'quality' => $item->quality_ordered?->value,
                ] : null,
                'expiry_date' => $item->expiry_date?->format('Y-m-d'),
                'cooling_status' => $item->cooling_status ?? false, // Transfer Ready
            ];
        })->toArray();
    }


    /**
     * Calculate price comparison for Via Purchasing Officer orders
     */
    private function calculatePriceComparison(): array
    {
        if ($this->order_type !== OrderType::VIA_PURCHASING_OFFICER) {
            return [];
        }

        $totalViaPO = (float) $this->total_amount;
        $totalFromDirectSupplier = 0;
        $itemSavings = [];

        // Calculate total from Direct Supplier for each item
        foreach ($this->items as $item) {
            $itemId = $item->item_id;
            $quantity = (float) $item->quantity_ordered;

            // Get direct supplier price for this item
            $directSupplierPrice = $this->getDirectSupplierPriceForItem($itemId, $quantity);

            if ($directSupplierPrice) {
                $itemTotalFromDirectSupplier = $directSupplierPrice * $quantity;
                $itemTotalViaPO = (float) $item->total_price;
                $itemSaving = $itemTotalFromDirectSupplier - $itemTotalViaPO;

                $totalFromDirectSupplier += $itemTotalFromDirectSupplier;
                $itemSavings[] = [
                    'item_name' => $item->item_name,
                    'direct_supplier_total' => round($itemTotalFromDirectSupplier, 2),
                    'via_po_total' => round($itemTotalViaPO, 2),
                    'savings' => round($itemSaving, 2),
                ];
            }
        }

        $totalSavings = $totalFromDirectSupplier - $totalViaPO;

        return [
            'total_amount_from_direct_supplier' => round($totalFromDirectSupplier, 2),
            'total_amount_via_purchasing_officer' => round($totalViaPO, 2),
            'savings' => round($totalSavings, 2),
            'total_expected_savings' => round(array_sum(array_column($itemSavings, 'savings')), 2),
        ];
    }

    /**
     * Get direct supplier price for an item
     */
    private function getDirectSupplierPriceForItem(string $itemId, float $quantity): ?float
    {
        // Get the best direct supplier price for this item
        $supplierItem = \Modules\Purchase\Models\SupplierItem::with('supplier')
            ->where('item_id', $itemId)
            ->whereHas('supplier', fn($q) => $q->active())
            ->where('is_available', true)
            ->orderBy('unit_price')
            ->first();

        if ($supplierItem) {
            return (float) $supplierItem->unit_price;
        }

        // Fallback: Get from recent orders
        $threeMonthsAgo = now()->subMonths(3);
        $recentOrderItem = \Modules\Purchase\Models\PurchaseOrderItem::with('purchaseOrder')
            ->where('item_id', $itemId)
            ->whereHas('purchaseOrder', function ($query) use ($threeMonthsAgo) {
                $query->where('order_type', OrderType::DIRECT_SUPPLIER)
                    ->where('created_at', '>=', $threeMonthsAgo)
                    ->whereIn('status', [
                        \Modules\Purchase\Enums\OrderStatus::CONFIRMED,
                        \Modules\Purchase\Enums\OrderStatus::CLOSED,
                        \Modules\Purchase\Enums\OrderStatus::DELIVERED,
                    ]);
            })
            ->orderBy('created_at', 'desc')
            ->first();

        return $recentOrderItem ? (float) $recentOrderItem->unit_price : null;
    }
}
