<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;

class DirectSupplierOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array for Direct Supplier Order details.
     *
     * According to requirements 3.1.2.4.3.2.1:
     * - Order Status with detailed information
     * - Available Actions
     * - Supplier Information
     * - Modification details (if any)
     */
    public function toArray($request): array
    {
        // Ensure this is a Direct Supplier Order
        if ($this->order_type !== OrderType::DIRECT_SUPPLIER) {
            return [];
        }

        $data = [
            'id' => $this->id,
            'order_number' => $this->order_number,

            // Basic Info
            'number_of_items' => $this->total_items,
            'date_time' => $this->submitted_at?->format('Y-m-d H:i:s')
                ?? $this->created_at?->format('Y-m-d H:i:s'),
            'date' => $this->submitted_at?->format('Y-m-d')
                ?? $this->created_at?->format('Y-m-d'),
            'time' => $this->submitted_at?->format('H:i:s')
                ?? $this->created_at?->format('H:i:s'),

            // Status Information
            'status' => $this->status?->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,

            // Supplier Information (same shape as Return details)
            'supplier' => $this->whenLoaded('supplier', function () {
                if (!$this->supplier) {
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
            }),

            // Timelines
            'timelines' => $this->whenLoaded('timelines', fn () => TimelineResource::collection($this->timelines)),

            // Status-specific details
            'status_details' => $this->getStatusDetails(),

            // Modification details (for Pending Your Approval status)
            'modifications' => $this->getModifications(),

            // Available Actions
            'available_actions' => $this->getAvailableActions(),

            // Items
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),

            // Financial Summary
            'subtotal' => (float) $this->subtotal,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'discount_amount' => (float) $this->discount_amount,
            'total_amount' => (float) $this->total_amount,

            // Delivery Information
            'preferred_delivery_date' => $this->preferred_delivery_date?->format('Y-m-d'),
            'latest_delivery_date' => $this->latest_delivery_date?->format('Y-m-d'),
            'expected_delivery_at' => $this->expected_delivery_at?->format('Y-m-d H:i:s'),
            'actual_delivery_at' => $this->actual_delivery_at?->format('Y-m-d H:i:s'),

            // Timestamps
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];

        return $data;
    }

    /**
     * Get status-specific details based on order status (Direct Supplier)
     */
    private function getStatusDetails(): array
    {
        $status = $this->status?->value;

        return match ($status) {
            'rejected' => [
                'supplier_name' => $this->supplier?->name ?? null,
                'supplier_image' => $this->supplier?->image_url ?? null,
                'rejection_reason' => $this->rejection_reason,
                'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),
                'rejected_by' => $this->whenLoaded('requestedBy', function () {
                    return [
                        'id' => $this->requestedBy->id,
                        'name' => $this->requestedBy->name,
                        'image' => $this->requestedBy->image_url ?? null,
                    ];
                }),
            ],
            'cancelled' => $this->getCancellationDetails(),
            'cancelled_by_branch' => $this->getCancellationDetails(),
            'cancelled_by_supplier' => $this->getCancellationDetails(),
            'pending_approval' => [
                'has_modifications' => $this->hasModifications(),
                'modification_count' => $this->getModificationCount(),
            ],
            'delayed' => [
                'delay_reason' => $this->message, // Using message field for delay reason
                'new_delivery_date' => $this->expected_delivery_at?->format('Y-m-d'),
                'new_delivery_time' => $this->expected_delivery_at?->format('H:i:s'),
                'delayed_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            ],
            'partial_confirmation' => [
                'confirmed_items_count' => $this->relationLoaded('items')
                    ? $this->items->whereNotNull('quantity_confirmed')->count()
                    : 0,
                'total_items_count' => $this->total_items,
            ],
            'confirmed' => [
                'confirmed_at' => $this->confirmed_at?->format('Y-m-d H:i:s'),
                'all_items_confirmed' => $this->relationLoaded('items')
                    ? $this->items->every(fn($item) => $item->quantity_confirmed !== null)
                    : false,
            ],
            'draft' => [
                'is_draft' => true,
                'saved_at' => $this->created_at?->format('Y-m-d H:i:s'),
            ],
            default => [],
        };
    }

    /**
     * Get modification details for items
     */
    private function getModifications(): ?array
    {
        if ($this->status?->value !== 'pending_approval') {
            return null;
        }

        if (!$this->relationLoaded('items') || $this->items->isEmpty()) {
            return null;
        }

        $modifications = [];

        foreach ($this->items as $item) {
            $itemModifications = [];

            // Quantity Change
            if ($item->original_quantity !== null && $item->new_quantity !== null) {
                $itemModifications['quantity_change'] = [
                    'requested_quantity' => (float) $item->original_quantity,
                    'proposed_new_quantity' => (float) $item->new_quantity,
                    'shortage_quantity' => (float) ($item->original_quantity - $item->new_quantity),
                    'modification_note' => $item->modification_note,
                ];
            }

            // Alternative Product Proposal
            if ($item->is_alternative) {
                $itemModifications['alternative_product'] = [
                    'item_name' => $item->item_name,
                    'item_logo' => $item->item_logo_url,
                    'quantity' => (float) $item->quantity_ordered,
                    'quality' => $item->quality_ordered?->value,
                    'unit_price' => (float) $item->unit_price,
                    'total_price' => (float) $item->total_price,
                    'modification_note' => $item->modification_note,
                ];
            }

            // Delivery Time Change (at order level, not item level)
            if ($this->expected_delivery_at && $this->preferred_delivery_date) {
                $itemModifications['delivery_time_change'] = [
                    'item_quantity_to_deliver' => (float) $item->quantity_ordered,
                    'new_delivery_date' => $this->expected_delivery_at->format('Y-m-d'),
                    'new_delivery_time' => $this->expected_delivery_at->format('H:i:s'),
                    'modification_note' => $this->message,
                ];
            }

            if (!empty($itemModifications)) {
                $modifications[] = array_merge([
                    'item_id' => $item->id,
                    'item_name' => $item->item_name,
                ], $itemModifications);
            }
        }

        return !empty($modifications) ? $modifications : null;
    }

    /**
     * Get available actions based on order status
     */
    private function getAvailableActions(): array
    {
        $actions = [];

        // Track (available for confirmed, preparing, on_the_way, delayed)
        if (in_array($this->status?->value, ['confirmed', 'preparing', 'on_the_way', 'delayed'])) {
            $actions[] = 'track';
        }

        // View Details (always available)
        $actions[] = 'view_details';

        // Timeline Tracking (always available)
        $actions[] = 'timeline_tracking';

        // Supplier Information (always available for direct supplier orders)
        $actions[] = 'supplier_information';

        // Approve modifications (for pending_approval)
        if ($this->status?->value === 'pending_approval') {
            $actions[] = 'approve_modifications';
            $actions[] = 'reject_modifications';
        }

        // Approve delay (for delayed)
        if ($this->status?->value === 'delayed') {
            $actions[] = 'approve_delay';
            $actions[] = 'reject_delay';
        }

        // Approve partial confirmation (for partial_confirmation)
        if ($this->status?->value === 'partial_confirmation') {
            $actions[] = 'approve_partial';
            $actions[] = 'reject_partial';
        }

        // Change source (for rejected)
        if ($this->status?->value === 'rejected') {
            $actions[] = 'change_source';
        }

        return $actions;
    }

    /**
     * Check if order has modifications
     */
    private function hasModifications(): bool
    {
        if (!$this->relationLoaded('items')) {
            return false;
        }

        return $this->items->some(function ($item) {
            return $item->original_quantity !== null
                || $item->is_alternative
                || $item->modification_note !== null;
        });
    }

    /**
     * Get count of modified items
     */
    private function getModificationCount(): int
    {
        if (!$this->relationLoaded('items')) {
            return 0;
        }

        return $this->items->filter(function ($item) {
            return $item->original_quantity !== null
                || $item->is_alternative
                || $item->modification_note !== null;
        })->count();
    }

    /**
     * Get cancellation details for canceled/cancelled orders
     */
    private function getCancellationDetails(): array
    {
        $status = $this->status?->value;
        
        // Only return cancellation_reason for branch/supplier cancellations
        $cancellationReason = null;
        if (in_array($status, ['cancelled_by_branch', 'cancelled_by_supplier'])) {
            $cancellationReason = $this->cancellation_reason ?? null;
        } elseif ($status === 'cancelled') {
            // For generic 'cancelled' status, use cancellation_reason if available, otherwise null
            $cancellationReason = $this->cancellation_reason ?? null;
        }

        return [
            'cancellation_reason' => $cancellationReason,
            'canceled_at' => $this->canceled_at?->format('Y-m-d H:i:s') ?? $this->rejected_at?->format('Y-m-d H:i:s'),
            'canceled_by' => $this->whenLoaded('requestedBy', function () {
                return [
                    'id' => $this->requestedBy->id,
                    'name' => $this->requestedBy->name,
                    'image' => $this->requestedBy->image_url ?? null,
                ];
            }),
        ];
    }
}
