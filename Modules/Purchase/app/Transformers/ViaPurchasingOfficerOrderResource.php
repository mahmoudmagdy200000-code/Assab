<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;

class ViaPurchasingOfficerOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array for Via Purchasing Officer Order details.
     *
     * According to requirements 3.1.2.4.3.3.1:
     * - Same scenario as Direct Supplier Order
     * - Order Status with detailed information
     * - Available Actions
     * - Store Information
     */
    public function toArray($request): array
    {
        // Ensure this is a Via Purchasing Officer Order
        if ($this->order_type !== OrderType::VIA_PURCHASING_OFFICER) {
            return [];
        }

        $data = [
            'id' => $this->id,
            'order_number' => $this->order_number,

            // Basic Info
            'number_of_items' => $this->total_items,
            'date_time' => $this->submitted_at?->timestamp
                ?? $this->created_at?->timestamp,
            'date' => $this->submitted_at?->timestamp
                ?? $this->created_at?->timestamp,
            'time' => $this->submitted_at?->timestamp
                ?? $this->created_at?->timestamp,

            // Status Information
            'status' => $this->status?->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,

            // Store/Branch Information
            'store' => $this->whenLoaded('branch', function () {
                return [
                    'id' => $this->branch->id,
                    'name' => $this->branch->name,
                    'location' => $this->branch->location,
                ];
            }),

            // Status-specific details (same as Direct Supplier)
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
            'preferred_delivery_date' => $this->preferred_delivery_date?->timestamp,
            'latest_delivery_date' => $this->latest_delivery_date?->timestamp,
            'expected_delivery_at' => $this->expected_delivery_at?->timestamp,
            'actual_delivery_at' => $this->actual_delivery_at?->timestamp,

            // Timestamps
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
        ];

        return $data;
    }

    /**
     * Get status-specific details based on order status (same as Direct Supplier)
     */
    private function getStatusDetails(): array
    {
        $status = $this->status?->value;

        return match ($status) {
            'rejected' => [
                'rejection_reason' => $this->rejection_reason,
                'rejected_at' => $this->rejected_at?->timestamp,
                'rejected_by' => $this->whenLoaded('requestedBy', function () {
                    return [
                        'id' => $this->requestedBy->id,
                        'name' => $this->requestedBy->name,
                    ];
                }),
            ],
            'canceled' => [
                'cancellation_reason' => $this->rejection_reason,
                'canceled_at' => $this->rejected_at?->timestamp,
                'canceled_by' => $this->whenLoaded('requestedBy', function () {
                    return [
                        'id' => $this->requestedBy->id,
                        'name' => $this->requestedBy->name,
                    ];
                }),
            ],
            'pending_approval' => [
                'has_modifications' => $this->hasModifications(),
                'modification_count' => $this->getModificationCount(),
            ],
            'delayed' => [
                'delay_reason' => $this->message,
                'new_delivery_date' => $this->expected_delivery_at?->timestamp,
                'new_delivery_time' => $this->expected_delivery_at?->timestamp,
                'delayed_at' => $this->updated_at?->timestamp,
            ],
            'partial_confirmation' => [
                'confirmed_items_count' => $this->relationLoaded('items')
                    ? $this->items->whereNotNull('quantity_confirmed')->count()
                    : 0,
                'total_items_count' => $this->total_items,
            ],
            'confirmed' => [
                'confirmed_at' => $this->confirmed_at?->timestamp,
                'all_items_confirmed' => $this->relationLoaded('items')
                    ? $this->items->every(fn($item) => $item->quantity_confirmed !== null)
                    : false,
            ],
            'draft' => [
                'is_draft' => true,
                'saved_at' => $this->created_at?->timestamp,
            ],
            default => [],
        };
    }

    /**
     * Get modification details for items (same as Direct Supplier)
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

            // Delivery Time Change
            if ($this->expected_delivery_at && $this->preferred_delivery_date) {
                $itemModifications['delivery_time_change'] = [
                    'item_quantity_to_deliver' => (float) $item->quantity_ordered,
                    'new_delivery_date' => $this->expected_delivery_at->timestamp,
                    'new_delivery_time' => $this->expected_delivery_at->timestamp,
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
     * Get available actions based on order status (same as Direct Supplier)
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

        // Store Information (always available)
        $actions[] = 'store_information';

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
}
