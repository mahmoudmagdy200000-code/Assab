<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Enums\OrderType;
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
            'request_summary' => [
                'request_no' => $this->order_number ?? 'n/a',
                'type' => $this->order_type?->value ?? 'n/a',
            ],
        ];
    }

    /**
     * Get Direct Supplier Order response
     */
    private function getDirectSupplierResponse(): array
    {
        return [
            'request_summary' => [
                'id' => $this->id,
                'status' => $this->status?->value ?? 'n/a',
                'order_number' => $this->order_number ?? 'n/a',
                'from' => [
                    'branch_name' => $this->branch?->name ?? 'n/a',
                    'branch_location' => $this->branch?->location ?? 'n/a',
                ],
                'type' => $this->order_type?->value ?? 'n/a',
                'supplier_name' => $this->supplier?->name ?? 'n/a',
                'total_amount' => $this->total_amount ? (float) $this->total_amount : 0.0,
                'message' => $this->message ?? 'n/a',
                'contact_methods' => $this->getContactMethodsWithDetails(),
            ],
            'product_details' => $this->whenLoaded('items', function () {
                if (!$this->items) {
                    return [];
                }
                return $this->items->map(function ($item) {
                    $itemData = [
                        'id' => $item->id ?? 'n/a',
                        'item_name' => $item->item_name ?? 'n/a',
                        'item_id' => $item->item_id ?? 'n/a',
                        'item_logo' => $item->item_logo_url ?? 'n/a',
                        'status' => $item->status?->value ?? 'pending',
                        'status_label' => $item->status?->label() ?? 'Pending',
                        'status_color' => $item->status?->color() ?? '#F59E0B',
                        'requested_qty' => $item->quantity_ordered ? (float) $item->quantity_ordered : 0.0,
                        'quantity_confirmed' => $item->quantity_confirmed ? (float) $item->quantity_confirmed : null,
                        'quality' => $item->quality_ordered?->value ?? 'n/a',
                        'item_price' => $item->unit_price ? (float) $item->unit_price : 0.0,
                        'item_unit' => $item->unit_of_measurement ?? 'n/a',
                        'total_price' => $item->total_price ? (float) $item->total_price : 0.0,
                    ];

                    // Add approval information if item needs approval or has approval data
                    if ($item->status?->needsApproval() || $item->approval_type) {
                        $itemData['approval_type'] = $item->approval_type;
                        $itemData['approval_data'] = $item->approval_data;
                        $itemData['can_approve'] = true; // Branch manager can approve
                        $itemData['can_reject'] = true; // Branch manager can reject
                    } else {
                        $itemData['can_approve'] = false;
                        $itemData['can_reject'] = false;
                    }

                    return $itemData;
                });
            }) ?? [],
        ];
    }

    /**
     * Get Via Purchasing Officer response
     */
    private function getViaPurchasingOfficerResponse(): array
    {
        return [
            'request_summary' => [
                'id' => $this->id,
                'status' => $this->status?->value ?? 'n/a',
                'order_number' => $this->order_number ?? 'n/a',
                'from' => [
                    'branch_name' => $this->branch?->name ?? 'n/a',
                    'branch_location' => $this->branch?->location ?? 'n/a',
                ],
                'type' => $this->order_type?->value ?? 'n/a',
                'requested_by' => $this->requestedBy?->name ?? 'n/a',
                'requested_date' => $this->created_at?->toDateTimeString() ?? 'n/a',
                'total_price' => $this->total_amount ? (float) $this->total_amount : 0.0,
            ],
            'product_details' => $this->whenLoaded('items', function () {
                if (!$this->items) {
                    return [];
                }
                return $this->items->map(function ($item) {
                    // Calculate price comparison for this specific item
                    $priceComparison = $this->calculatePriceComparisonForItem($item);

                    return [
                        'id' => $item->id ?? 'n/a',
                        'item_unit' => $item->unit_of_measurement ?? 'n/a',
                        'item_id' => $item->item_id ?? 'n/a',
                        'item_name' => $item->item_name ?? 'n/a',
                        'item_logo' => $item->item_logo_url,
                        'item_price' => $item->unit_price ? (float) $item->unit_price : 0.0,
                        'requested_qty' => $item->quantity_ordered ? (float) $item->quantity_ordered : 0.0,
                        'quality' => $item->quality_ordered?->value ?? 'n/a',
                        'preferred_delivery_date' => $this->preferred_delivery_date?->format('Y-m-d') ?? 'n/a',
                        'latest_delivery_date' => $this->latest_delivery_date?->format('Y-m-d') ?? 'n/a',
                        'special_instructions' => $this->special_instructions ?? 'n/a',
                        'price_comparison' => $priceComparison,
                    ];
                });
            }) ?? [],
        ];
    }

    /**
     * Get Internal Transfer response (keep existing structure)
     */
    private function getInternalTransferResponse(): array
    {
        // Get branch name only (for product details)
        $fromBranchNameOnly = $this->whenLoaded('fromBranch', function () {
            return $this->fromBranch->name ?? 'n/a';
        });

        return [
            'request_summary' => [
                'id' => $this->id,
                'status' => $this->status?->value ?? 'n/a',
                'request_no' => $this->order_number ?? 'n/a',
                'type' => $this->order_type?->value ?? 'n/a',
                'from' => $this->getFromData(),
                'requested_by' => $this->whenLoaded('requestedBy', function () {
                    return [
                        'id' => $this->requestedBy->id ?? 'n/a',
                        'name' => $this->requestedBy->name ?? 'n/a',
                    ];
                }) ?? ['id' => 'n/a', 'name' => 'n/a'],
                'priority' => $this->priority?->value ?? 'n/a',
                'request_date' => $this->created_at?->toDateTimeString() ?? 'n/a',
                'justification' => $this->message ?? 'n/a',
            ],
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
                        ?? 0.0;
                    $availableQuantity = (float) $availableQuantity;

                    // Balance after = actual available (available - reserved) from inventory
                    $balanceAfter = $inventory
                        ? ($inventory->available_quantity - $inventory->reserved_quantity)
                        : ($item->remaining_balance ?? 0.0);
                    $balanceAfter = (float) $balanceAfter;

                    // Quality Grade - prefer inventory quality, then quality_received, then quality_ordered
                    $qualityGrade = $inventory?->quality?->value
                        ?? $item->quality_received?->value
                        ?? $item->quality_ordered?->value
                        ?? 'n/a';

                    // Expiry date - prefer inventory earliest_expiry_date, then item expiry_date
                    $expiryDate = $inventory?->earliest_expiry_date
                        ?? $item->expiry_date;
                    $expiryDateFormatted = $expiryDate
                        ? Carbon::parse($expiryDate)->format('Y-m-d')
                        : 'n/a';

                    // Cooling status - prefer inventory cooling_status, then item cooling_status
                    if ($inventory?->cooling_status !== null) {
                        $coolingStatus = (bool) $inventory->cooling_status;
                    } elseif ($item->cooling_status !== null) {
                        $coolingStatus = (bool) $item->cooling_status;
                    } else {
                        $coolingStatus = false;
                    }

                    return [
                        'id' => $item->id ?? 'n/a',
                        'item_name' => $item->item_name ?? 'n/a',
                        'requested_qty' => $item->quantity_ordered ? (float) $item->quantity_ordered : 0.0,
                        'available_in_branch_name' => $fromBranchNameOnly ?? 'n/a',
                        'available_in_quantity' => $availableQuantity,
                        'balance_after' => $balanceAfter,
                        'quality_grade' => $qualityGrade,
                        'expiry_date' => $expiryDateFormatted,
                        'cooling_status' => $coolingStatus,
                        'item_unit' => $item->unit_of_measurement ?? 'n/a',
                    ];
                });
            }) ?? [],
        ];
    }

    /**
     * Calculate price comparison for a single item in Via Purchasing Officer orders
     *
     * @param mixed $item
     * @return array
     */
    private function calculatePriceComparisonForItem($item): array
    {
        $itemId = $item->item_id;
        $quantity = (float) $item->quantity_ordered;

        if (!$itemId) {
            return [
                'direct_supplier_price_same_item' => 'n/a',
                'VIA_PURCHASING_OFFICER_same_item' => 'n/a',
                'saving_amount' => 'n/a',
            ];
        }

        // Get direct supplier price for this item
        $directSupplierPrice = $this->getDirectSupplierPriceForItem($itemId);
        $directSupplierTotal = $directSupplierPrice ? ($directSupplierPrice * $quantity) : 0.0;

        // Get via purchasing officer price (from current order item)
        $viaPOTotal = $item->total_price ? (float) $item->total_price : 0.0;

        // Calculate saving
        $savingAmount = $directSupplierTotal - $viaPOTotal;

        return [
            'direct_supplier_price_same_item' => $directSupplierTotal > 0 ? round($directSupplierTotal, 2) : 'n/a',
            'VIA_PURCHASING_OFFICER_same_item' => $viaPOTotal > 0 ? round($viaPOTotal, 2) : 'n/a',
            'saving_amount' => $savingAmount != 0 ? round($savingAmount, 2) : 'n/a',
        ];
    }

    /**
     * Get direct supplier price for an item
     */
    private function getDirectSupplierPriceForItem(string $itemId): ?float
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

    /**
     * Get 'from' data based on order type
     *
     * Returns:
     * - For INTERNAL_TRANSFER: Branch information (id, name, location)
     * - For DIRECT_SUPPLIER: Supplier information (id, name, image, status)
     * - For VIA_PURCHASING_OFFICER: null (no source branch/supplier)
     *
     * @return array|string
     */
    private function getFromData(): array|string
    {
        if (!$this->order_type) {
            return 'n/a';
        }

        return match ($this->order_type) {
            OrderType::INTERNAL_TRANSFER => $this->getFromBranchData(),
            OrderType::DIRECT_SUPPLIER => $this->getSupplierData(),
            OrderType::VIA_PURCHASING_OFFICER => 'n/a',
            default => 'n/a',
        };
    }

    /**
     * Get from branch data for internal transfer orders
     *
     * @return array|string
     */
    private function getFromBranchData(): array|string
    {
        // Check if fromBranch is loaded or if from_branch_id exists
        if ($this->relationLoaded('fromBranch') && $this->fromBranch) {
            return [
                'id' => $this->fromBranch->id ?? 'n/a',
                'name' => $this->fromBranch->name ?? 'n/a',
                'location' => $this->fromBranch->location ?? 'n/a',
            ];
        }

        // If from_branch_id exists but relation not loaded, return n/a
        return 'n/a';
    }

    /**
     * Get supplier data for direct supplier orders
     *
     * @return array|string
     */
    private function getSupplierData(): array|string
    {
        // Check if supplier is loaded
        if ($this->relationLoaded('supplier') && $this->supplier) {
            return [
                'id' => $this->supplier->id ?? 'n/a',
                'name' => $this->supplier->name ?? 'n/a',
                'image' => $this->supplier->image_url ?? 'n/a',
                'status' => $this->supplier->status?->value ?? 'n/a',
            ];
        }

        // If supplier_id exists but relation not loaded, return n/a
        return 'n/a';
    }

    /**
     * Get contact methods with their details (email, phone, etc.)
     *
     * @return array
     */
    private function getContactMethodsWithDetails(): array
    {
        // Get contact methods from order or supplier
        $contactMethods = $this->notification_channels ?? $this->supplier?->contact_methods ?? [];

        if (empty($contactMethods) || !is_array($contactMethods)) {
            return [];
        }

        $supplierEmail = $this->supplier?->email ?? null;
        $supplierPhone = $this->supplier?->phone ?? null;

        return array_map(function ($method) use ($supplierEmail, $supplierPhone) {
            $contactDetail = match ($method) {
                'email' => $supplierEmail ?? 'n/a',
                'whatsapp', 'sms' => $supplierPhone ?? 'n/a',
                'app' => 'n/a', // In-App doesn't need contact detail
                default => 'n/a',
            };

            return [
                'method' => $method,
                'contact_detail' => $contactDetail,
            ];
        }, $contactMethods);
    }
}
