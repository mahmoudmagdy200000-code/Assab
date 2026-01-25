<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;

class InternalTransferOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array for Internal Transfer Order details.
     *
     * According to requirements 3.1.2.4.3.4.1:
     * - Order Status with detailed information
     * - Available Actions
     * - Store/Branch Information
     */
    public function toArray($request): array
    {
        // Ensure this is an Internal Transfer Order
        if ($this->order_type !== OrderType::INTERNAL_TRANSFER) {
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

            // Source Branch Information (from_branch)
            'from_branch' => $this->whenLoaded('fromBranch', function () {
                if (!$this->fromBranch) {
                    return null;
                }
                return [
                    'id' => $this->fromBranch->id,
                    'name' => $this->fromBranch->name,
                    'image' => $this->fromBranch->image ? asset('storage/' . $this->fromBranch->image) : null,
                    'lat' => $this->fromBranch->lat ? (float) $this->fromBranch->lat : null,
                    'lng' => $this->fromBranch->lng ? (float) $this->fromBranch->lng : null,
                    'opening_hours' => $this->fromBranch->opening_hours ?? null,
                    'closing_hours' => $this->fromBranch->closing_hours ?? null,
                ];
            }),

            // Current Branch Information
            'branch' => $this->whenLoaded('branch', function () {
                if (!$this->branch) {
                    return null;
                }
                return [
                    'id' => $this->branch->id,
                    'name' => $this->branch->name,
                    'image' => $this->branch->image ? asset('storage/' . $this->branch->image) : null,
                    'lat' => $this->branch->lat ? (float) $this->branch->lat : null,
                    'lng' => $this->branch->lng ? (float) $this->branch->lng : null,
                    'opening_hours' => $this->branch->opening_hours ?? null,
                    'closing_hours' => $this->branch->closing_hours ?? null,
                ];
            }),

            // Status-specific details
            'status_details' => $this->getStatusDetails(),

            // Available Actions
            'available_actions' => $this->getAvailableActions(),

            // Items
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),

            // Timestamps
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];

        $data['supplier'] = null;
        $data['timelines'] = $this->whenLoaded('timelines', fn () => TimelineResource::collection($this->timelines));

        return $data;
    }

    /**
     * Get status-specific details based on order status
     */
    private function getStatusDetails(): array
    {
        $status = $this->status?->value;

        return match ($status) {
            'rejected' => [
                'branch_manager_name' => $this->whenLoaded('requestedBy', fn() => $this->requestedBy->name) ?? null,
                'branch_manager_image' => $this->whenLoaded('requestedBy', fn() => $this->requestedBy->image_url) ?? null,
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
            'partial_confirmation' => [
                'confirmed_items_count' => $this->relationLoaded('items')
                    ? $this->items->whereNotNull('quantity_confirmed')->count()
                    : 0,
                'total_items_count' => $this->total_items,
                'partial_confirmed_at' => $this->confirmed_at?->format('Y-m-d H:i:s'),
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
            'pending' => [
                'pending_since' => $this->submitted_at?->format('Y-m-d H:i:s')
                    ?? $this->created_at?->format('Y-m-d H:i:s'),
            ],
            default => [],
        };
    }

    /**
     * Get available actions based on order status
     */
    private function getAvailableActions(): array
    {
        $actions = [];

        // View Details (always available)
        $actions[] = 'view_details';

        // Timeline Tracking (always available)
        $actions[] = 'timeline_tracking';

        // Store Information (always available - from_branch info)
        $actions[] = 'store_information';

        // Approve (for pending)
        if ($this->status?->value === 'pending') {
            $actions[] = 'approve';
            $actions[] = 'reject';
        }

        // Approve partial (for partial_confirmation)
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
}
