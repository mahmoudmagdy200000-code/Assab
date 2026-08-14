<?php

namespace Modules\Purchase\Transformers;

use App\Http\Resources\UnifiedTimelineResource;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\TimelineEventType;
use Modules\Purchase\Models\BranchInventory;

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
                'reason_for_rejected' => $this->getReasonForRejected(),
            ],
        ];
    }

    /**
     * Get reason for rejected/cancelled order
     * Returns full object with cancellation/rejection details, null otherwise
     * Only returns cancellation_reason if cancelled by branch or supplier
     */
    private function getReasonForRejected(): ?array
    {
        $status = $this->status;

        if (! $status) {
            return null;
        }

        // Check if order is cancelled by branch or supplier only
        if (in_array($status, [
            OrderStatus::CANCELLED_BY_BRANCH,
            OrderStatus::CANCELLED_BY_SUPPLIER,
        ])) {
            $cancelledBy = $this->getCancelledByInfo($status);

            return [
                'cancellation_reason' => $this->cancellation_reason ?? null,
                'cancelled_at' => $this->canceled_at?->format('Y-m-d H:i:s'),
                'cancelled_by' => $cancelledBy,
            ];
        }

        // Delay rejected by branch (same structure as cancellation)
        if ($status === OrderStatus::DELAYED_CANCELED) {
            $cancelledBy = $this->getDelayBranchManagerForRejection();

            return [
                'cancellation_reason' => $this->cancellation_reason ?? null,
                'cancelled_at' => $this->canceled_at?->format('Y-m-d\TH:i:s\Z'),
                'cancelled_by' => $cancelledBy,
            ];
        }

        // For generic CANCELED status (not by branch or supplier), return null
        if ($status === OrderStatus::CANCELED) {
            return null;
        }

        // Check if order is rejected
        if ($status === OrderStatus::REJECTED) {
            $rejectedBy = null;
            // Dashboard-side rejection: the true actor is the ASAB user stamped
            // by the decision bridge — showing the order CREATOR here misled the
            // branch into thinking they rejected their own order.
            if ($this->decided_by_asab_user_id !== null) {
                $asabActor = \Modules\Admin\Models\AsabUser::withoutGlobalScopes()
                    ->whereKey($this->decided_by_asab_user_id)->first(['id', 'name']);
                if ($asabActor !== null) {
                    $rejectedBy = [
                        'id' => $asabActor->id,
                        'name' => $asabActor->name,
                        'type' => 'accountant',
                        'image' => null,
                    ];
                }
            }
            if ($rejectedBy === null && $this->relationLoaded('requestedBy') && $this->requestedBy) {
                $rejectedBy = [
                    'id' => $this->requestedBy->id ?? null,
                    'name' => $this->requestedBy->name ?? null,
                    'type' => 'branch_manager',
                    'image' => $this->requestedBy->image_url ?? null,
                ];
            }

            return [
                'rejection_reason' => $this->rejection_reason,
                'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),
                'rejected_by' => $rejectedBy,
            ];
        }

        // For all other statuses, return null
        return null;
    }

    /**
     * Get information about who cancelled the order
     */
    private function getCancelledByInfo(OrderStatus $status): ?array
    {
        // Check if cancelled by supplier
        if ($status === OrderStatus::CANCELLED_BY_SUPPLIER) {
            if ($this->relationLoaded('supplier') && $this->supplier) {
                return [
                    'id' => $this->supplier->id ?? null,
                    'name' => $this->supplier->name ?? null,
                    'type' => 'supplier',
                    'image' => $this->supplier->image_url ?? null,
                ];
            }
        }

        // Check if cancelled by branch manager (CANCELLED_BY_BRANCH, CANCELED, or DELAYED_CANCELED)
        if (in_array($status, [OrderStatus::CANCELLED_BY_BRANCH, OrderStatus::CANCELED, OrderStatus::DELAYED_CANCELED])) {
            if ($this->relationLoaded('requestedBy') && $this->requestedBy) {
                return [
                    'id' => $this->requestedBy->id ?? null,
                    'name' => $this->requestedBy->name ?? null,
                    'type' => 'branch_manager',
                    'image' => $this->requestedBy->image_url ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Branch manager who rejected the delay (for reason_for_rejected when status is DELAYED_CANCELED).
     */
    private function getDelayBranchManagerForRejection(): ?array
    {
        $branchManager = $this->getDelayBranchManager();
        if ($branchManager) {
            $branchManager['type'] = 'branch_manager';

            return $branchManager;
        }

        return $this->getCancelledByInfo(OrderStatus::DELAYED_CANCELED);
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
                    'location' => $this->branch?->location ?? null,
                    'lat' => $this->branch?->lat ? (float) $this->branch->lat : null,
                    'lng' => $this->branch?->lng ? (float) $this->branch->lng : null,
                ],
                'type' => $this->order_type?->value ?? 'n/a',
                'supplier_name' => $this->supplier?->name ?? 'n/a',
                'total_amount' => $this->total_amount ? (float) $this->total_amount : 0.0,
                'message' => $this->message ?? 'n/a',
                'contact_methods' => $this->getContactMethodsWithDetails(),
                'reason_for_rejected' => $this->getReasonForRejected(),
                'delay_details' => $this->getDelayDetails(),
            ],
            'product_details' => $this->whenLoaded('items', function () {
                if (! $this->items) {
                    return [];
                }

                return $this->items->map(function ($item) {
                    $hasVariance = $item->quantity_received !== null && (float) ($item->quantity_variance ?? 0) != 0;
                    $effectiveTotal = $hasVariance && $item->quantity_received !== null
                        ? round((float) $item->quantity_received * (float) $item->unit_price - (float) ($item->discount ?? 0), 2)
                        : (float) ($item->total_price ?? 0);
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
                        'quantity_received' => $item->quantity_received !== null ? (float) $item->quantity_received : null,
                        'has_variance' => $hasVariance,
                        'effective_quantity' => $item->quantity_received !== null ? (float) $item->quantity_received : (float) ($item->quantity_ordered ?? 0),
                        'quality' => $item->quality_ordered?->value ?? 'n/a',
                        'item_price' => $item->unit_price ? (float) $item->unit_price : 0.0,
                        'item_unit' => $item->unit_of_measurement ?? 'n/a',
                        'total_price' => $effectiveTotal,
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
                })->all();
            }) ?? [],
            'supplier' => $this->supplierFragment(),
            'timelines' => $this->whenLoaded('timelines', fn () => UnifiedTimelineResource::collection($this->timelines)),
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
                    'location' => $this->branch?->location ?? 'n/a',
                    'lat' => $this->branch?->lat ? (float) $this->branch->lat : null,
                    'lng' => $this->branch?->lng ? (float) $this->branch->lng : null,
                ],
                'type' => $this->order_type?->value ?? 'n/a',
                'requested_by' => $this->requestedBy?->name ?? 'n/a',
                'requested_date' => $this->created_at?->toDateTimeString() ?? 'n/a',
                'total_price' => $this->total_amount ? (float) $this->total_amount : 0.0,
                'reason_for_rejected' => $this->getReasonForRejected(),
                'delay_details' => $this->getDelayDetails(),
            ],
            'product_details' => $this->whenLoaded('items', function () {
                if (! $this->items) {
                    return [];
                }

                return $this->items->map(function ($item) {
                    $hasVariance = $item->quantity_received !== null && (float) ($item->quantity_variance ?? 0) != 0;
                    $effectiveTotal = $hasVariance && $item->quantity_received !== null
                        ? round((float) $item->quantity_received * (float) $item->unit_price - (float) ($item->discount ?? 0), 2)
                        : (float) ($item->total_price ?? 0);
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
                        'quantity_received' => $item->quantity_received !== null ? (float) $item->quantity_received : null,
                        'has_variance' => $hasVariance,
                        'effective_quantity' => $item->quantity_received !== null ? (float) $item->quantity_received : (float) ($item->quantity_ordered ?? 0),
                        'total_price' => $effectiveTotal,
                        'quality' => $item->quality_ordered?->value ?? 'n/a',
                        'preferred_delivery_date' => $this->preferred_delivery_date?->format('Y-m-d') ?? 'n/a',
                        'latest_delivery_date' => $this->latest_delivery_date?->format('Y-m-d') ?? 'n/a',
                        'special_instructions' => $this->special_instructions ?? 'n/a',
                        'price_comparison' => $priceComparison,
                    ];
                })->all();
            }) ?? [],
            'supplier' => null,
            'timelines' => $this->whenLoaded('timelines', fn () => UnifiedTimelineResource::collection($this->timelines)),
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
                        'image' => $this->requestedBy->image_url ?? null,
                    ];
                }) ?? ['id' => 'n/a', 'name' => 'n/a', 'image' => null],
                'priority' => $this->priority?->value ?? 'n/a',
                'request_date' => $this->created_at?->toDateTimeString() ?? 'n/a',
                'justification' => $this->message ?? 'n/a',
                'reason_for_rejected' => $this->getReasonForRejected(),
                'delay_details' => $this->getDelayDetails(),
            ],
            'product_details' => $this->whenLoaded('items', function () use ($fromBranchNameOnly) {
                // Get from_branch_id for inventory lookup
                $fromBranchId = $this->from_branch_id;

                // Eager load all inventory records for items at once (performance optimization)
                $inventories = collect();
                if ($fromBranchId && $this->items->isNotEmpty()) {
                    $itemIds = $this->items->pluck('item_id')->filter()->unique()->toArray();
                    if (! empty($itemIds)) {
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
                        'quantity_received' => $item->quantity_received !== null ? (float) $item->quantity_received : null,
                        'has_variance' => $item->quantity_received !== null && (float) ($item->quantity_variance ?? 0) != 0,
                        'effective_quantity' => $item->quantity_received !== null ? (float) $item->quantity_received : (float) ($item->quantity_ordered ?? 0),
                        'available_in_branch_name' => $fromBranchNameOnly ?? 'n/a',
                        'available_in_quantity' => $availableQuantity,
                        'balance_after' => $balanceAfter,
                        'quality_grade' => $qualityGrade,
                        'expiry_date' => $expiryDateFormatted,
                        'cooling_status' => $coolingStatus,
                        'item_unit' => $item->unit_of_measurement ?? 'n/a',
                    ];
                })->all();
            }) ?? [],
            'supplier' => null,
            'timelines' => $this->whenLoaded('timelines', fn () => UnifiedTimelineResource::collection($this->timelines)),
        ];
    }

    /**
     * Supplier fragment (same shape as Return details). Null when no supplier.
     */
    private function supplierFragment(): ?array
    {
        if (! $this->relationLoaded('supplier') || ! $this->supplier) {
            return null;
        }

        return [
            'id' => $this->supplier->id,
            'name' => $this->supplier->name,
            'image' => $this->supplier->image_url ?? null,
            'status' => $this->supplier->status ?? 'offline',
            'status_label' => $this->supplier->status_label ?? 'Offline',
            'contact_methods' => $this->supplier->contact_methods ?? [],
            'average_response_time_hours' => $this->supplier->average_response_time_hours !== null
                ? (float) $this->supplier->average_response_time_hours
                : null,
            'response_rate_percentage' => $this->supplier->response_rate_percentage !== null
                ? (float) $this->supplier->response_rate_percentage
                : null,
        ];
    }

    /**
     * Calculate price comparison for a single item in Via Purchasing Officer orders
     *
     * @param  mixed  $item
     */
    private function calculatePriceComparisonForItem($item): array
    {
        $itemId = $item->item_id;
        $quantity = (float) $item->quantity_ordered;

        if (! $itemId) {
            return [
                'direct_supplier_price_same_item' => 0.0,
                'VIA_PURCHASING_OFFICER_same_item' => 0.0,
                'saving_amount' => 0.0,
                'has_comparison' => false,
            ];
        }

        // Get direct supplier price for this item
        $directSupplierPrice = $this->getDirectSupplierPriceForItem($itemId);
        $directSupplierTotal = $directSupplierPrice ? ($directSupplierPrice * $quantity) : 0.0;

        // Get via purchasing officer price (from current order item)
        $viaPOTotal = $item->total_price ? (float) $item->total_price : 0.0;

        // Calculate saving
        $savingAmount = $directSupplierTotal - $viaPOTotal;

        // Always numeric — the app casts these `as num`, so a string here
        // ("n/a") crashes the whole Via Purchasing Officer details screen.
        // `has_comparison` carries the "no data" signal instead.
        return [
            'direct_supplier_price_same_item' => round($directSupplierTotal, 2),
            'VIA_PURCHASING_OFFICER_same_item' => round($viaPOTotal, 2),
            'saving_amount' => round($savingAmount, 2),
            'has_comparison' => $directSupplierTotal > 0,
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
            ->whereHas('supplier', fn ($q) => $q->active())
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
     */
    private function getFromData(): array|string
    {
        if (! $this->order_type) {
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
     */
    private function getFromBranchData(): array|string
    {
        // Check if fromBranch is loaded or if from_branch_id exists
        if ($this->relationLoaded('fromBranch') && $this->fromBranch) {
            return [
                'id' => $this->fromBranch->id ?? 'n/a',
                'name' => $this->fromBranch->name ?? 'n/a',
                'location' => $this->fromBranch->location ?? null,
                'lat' => $this->fromBranch->lat ? (float) $this->fromBranch->lat : null,
                'lng' => $this->fromBranch->lng ? (float) $this->fromBranch->lng : null,
            ];
        }

        // If from_branch_id exists but relation not loaded, return n/a
        return 'n/a';
    }

    /**
     * Get supplier data for direct supplier orders
     */
    private function getSupplierData(): array|string
    {
        // Check if supplier is loaded
        if ($this->relationLoaded('supplier') && $this->supplier) {
            return [
                'id' => $this->supplier->id ?? 'n/a',
                'name' => $this->supplier->name ?? 'n/a',
                'image' => $this->supplier->image_url ?? 'n/a',
                // Plain string column, not an enum cast (see line 424) — `?->value`
                // on a string is a warning Laravel escalates to a 500.
                'status' => $this->supplier->status ?? 'n/a',
            ];
        }

        // If supplier_id exists but relation not loaded, return n/a
        return 'n/a';
    }

    /**
     * Get delay details if order is delayed
     */
    private function getDelayDetails(): ?array
    {
        // Only return delay details if order status is DELAYED, DELAYED_CONFIRMED, or DELAYED_CANCELED
        if (! in_array($this->status, [
            OrderStatus::DELAYED,
            OrderStatus::DELAYED_CONFIRMED,
            OrderStatus::DELAYED_CANCELED,
        ])) {
            return null;
        }

        return [
            'delay_reason' => $this->getDelayReasonMessage(),
            'new_expected_delivery_date' => $this->expected_delivery_at?->format('Y-m-d') ?? null,
            'new_expected_delivery_time' => $this->expected_delivery_at?->format('h:i A') ?? null,
            'delay_attachment' => $this->getDelayAttachment(),
            'delay_reported_at' => $this->getDelayReportedAt(),
            'delay_approved_at' => $this->getDelayApprovedAt(),
            'delay_rejected_at' => $this->getDelayRejectedAt(),
            'branch_manager' => $this->getDelayBranchManager(),
        ];
    }

    /**
     * When did supplier report the delay (from first DELIVERY_DELAYED timeline event).
     */
    private function getDelayReportedAt(): ?string
    {
        if (! $this->relationLoaded('timelines')) {
            return null;
        }
        $event = $this->timelines
            ->where('event_type', TimelineEventType::DELIVERY_DELAYED)
            ->sortBy('occurred_at')
            ->first();

        return $event?->occurred_at?->format('Y-m-d H:i:s');
    }

    /**
     * When did branch manager approve the delay (from first delay approval timeline event).
     */
    private function getDelayApprovedAt(): ?string
    {
        if (! $this->relationLoaded('timelines')) {
            return null;
        }
        $event = $this->timelines
            ->filter(fn ($t) => $t->event_type === TimelineEventType::APPROVAL_GRANTED
                && ($t->metadata['approval_type'] ?? null) === 'delay')
            ->sortBy('occurred_at')
            ->first();

        return $event?->occurred_at?->format('Y-m-d H:i:s');
    }

    /**
     * When did branch manager reject the delay (from first delay rejection timeline event).
     */
    private function getDelayRejectedAt(): ?string
    {
        if (! $this->relationLoaded('timelines')) {
            return null;
        }
        $event = $this->timelines
            ->filter(fn ($t) => $t->event_type === TimelineEventType::APPROVAL_DENIED
                && ($t->metadata['approval_type'] ?? null) === 'delay')
            ->sortBy('occurred_at')
            ->first();

        return $event?->occurred_at?->format('Y-m-d H:i:s');
    }

    /**
     * Branch manager who approved or rejected the delay (from timeline actor).
     */
    private function getDelayBranchManager(): ?array
    {
        if (! $this->relationLoaded('timelines')) {
            return $this->getDelayBranchManagerFromRequestedBy();
        }
        $approveEvent = $this->timelines
            ->filter(fn ($t) => $t->event_type === TimelineEventType::APPROVAL_GRANTED
                && ($t->metadata['approval_type'] ?? null) === 'delay')
            ->sortByDesc('occurred_at')
            ->first();
        $rejectEvent = $this->timelines
            ->filter(fn ($t) => $t->event_type === TimelineEventType::APPROVAL_DENIED
                && ($t->metadata['approval_type'] ?? null) === 'delay')
            ->sortByDesc('occurred_at')
            ->first();
        $event = $rejectEvent ?? $approveEvent;
        if ($event && $event->actor_id && $event->actor_name) {
            return [
                'id' => $event->actor_id,
                'name' => $event->actor_name,
                'image' => $event->actor_image_url ?? null,
            ];
        }

        return $this->getDelayBranchManagerFromRequestedBy();
    }

    /**
     * Fallback: branch manager from order requestedBy (when timeline actor not available).
     */
    private function getDelayBranchManagerFromRequestedBy(): ?array
    {
        if (! $this->relationLoaded('requestedBy') || ! $this->requestedBy) {
            return null;
        }

        return [
            'id' => $this->requestedBy->id,
            'name' => $this->requestedBy->name,
            'image' => $this->requestedBy->image_url ?? null,
        ];
    }

    /**
     * Get delay reason as message only (not JSON map).
     * If delay_reason is stored as JSON with "message" key, return that; otherwise return as-is.
     */
    private function getDelayReasonMessage(): ?string
    {
        $reason = $this->delay_reason ?? null;
        if ($reason === null || $reason === '') {
            return null;
        }
        $decoded = json_decode($reason, true);
        if (is_array($decoded) && isset($decoded['message'])) {
            return (string) $decoded['message'];
        }

        return $reason;
    }

    /**
     * Get delay attachment if exists.
     * Uses FileResource structure (id, file_name, file_type, file_size, url, uploaded_at).
     * First checks delay_reason JSON for photo path; otherwise delay-related documents.
     * No null values: strings default to '', file_size to 0.
     */
    private function getDelayAttachment(): ?array
    {
        $reason = $this->delay_reason ?? null;
        if (is_string($reason)) {
            $decoded = json_decode($reason, true);
            if (is_array($decoded) && ! empty($decoded['photo'])) {
                $photoPath = $decoded['photo'];
                $fileResource = FileResource::make($photoPath)->toArray(request());

                return $this->fileResourceWithoutNulls($fileResource);
            }
        }

        if (! $this->relationLoaded('documents')) {
            return null;
        }

        $delayDocument = $this->documents
            ->filter(function ($doc) {
                if (! in_array($doc->type, [DocumentType::PHOTO, DocumentType::OTHER])) {
                    return false;
                }
                $title = strtolower($doc->title ?? '');
                $description = strtolower($doc->description ?? '');
                $keywords = ['delay', 'delayed', 'تأخير'];
                foreach ($keywords as $keyword) {
                    if (str_contains($title, $keyword) || str_contains($description, $keyword)) {
                        return true;
                    }
                }

                return false;
            })
            ->first();

        if (! $delayDocument) {
            return null;
        }

        $fileResource = FileResource::make($delayDocument)->toArray(request());

        return $this->fileResourceWithoutNulls($fileResource);
    }

    /**
     * Ensure FileResource-shaped array has no null values (same object contract).
     *
     * @param  array<string, mixed>  $arr
     * @return array<string, mixed>
     */
    private function fileResourceWithoutNulls(array $arr): array
    {
        return [
            'id' => $arr['id'] ?? '',
            'file_name' => $arr['file_name'] ?? '',
            'file_type' => $arr['file_type'] ?? '',
            'file_size' => isset($arr['file_size']) ? (int) $arr['file_size'] : 0,
            'url' => $arr['url'] ?? '',
            'uploaded_at' => $arr['uploaded_at'] ?? Carbon::now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get contact methods with their details (email, phone, etc.)
     */
    private function getContactMethodsWithDetails(): array
    {
        // Get contact methods from order or supplier
        $contactMethods = $this->notification_channels ?? $this->supplier?->contact_methods ?? [];

        if (empty($contactMethods) || ! is_array($contactMethods)) {
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
