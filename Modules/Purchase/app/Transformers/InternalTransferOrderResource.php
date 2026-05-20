<?php

namespace Modules\Purchase\Transformers;

use App\Http\Resources\UnifiedTimelineResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Branch\Transformers\BranchResource;
use Modules\BranchManagers\Transformers\BranchManagerResource;
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

            // Type: Internal Transfer from Another Branch
            'type' => 'internal_transfer',
            'type_label' => 'Internal Transfer from Another Branch',

            // Status Information
            'status' => $this->status?->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,

            // From: Branch Location and Name
            'from' => $this->whenLoaded('fromBranch', function () {
                if (! $this->fromBranch) {
                    return null;
                }

                return [
                    'id' => $this->fromBranch->id,
                    'name' => $this->fromBranch->name,
                    'location' => $this->fromBranch->location ?? null,
                    'lat' => $this->fromBranch->lat ? (float) $this->fromBranch->lat : null,
                    'lng' => $this->fromBranch->lng ? (float) $this->fromBranch->lng : null,
                ];
            }),

            // Source Branch Information (from_branch) - Full resource
            'from_branch' => $this->whenLoaded('fromBranch', function () {
                return $this->fromBranch ? new BranchResource($this->fromBranch) : null;
            }),

            // Requested BY: Branch Manager Name (Me)
            'requested_by' => $this->whenLoaded('requestedBy', function () {
                if (! $this->requestedBy) {
                    return [
                        'name' => 'Me',
                        'image' => null,
                    ];
                }

                return [
                    'id' => $this->requestedBy->id,
                    'name' => $this->requestedBy->name,
                    'image' => $this->requestedBy->image_url ?? null,
                ];
            }) ?? [
                'name' => 'Me',
                'image' => null,
            ],

            // Priority
            'priority' => $this->priority ?? 'normal',
            'priority_label' => $this->priority ? ucfirst($this->priority) : 'Normal',

            // Current Branch Information
            'branch' => $this->whenLoaded('branch', function () {
                return $this->branch ? new BranchResource($this->branch) : null;
            }),

            // Status-specific details
            'status_details' => $this->getStatusDetails(),

            // Available Actions
            'available_actions' => $this->getAvailableActions(),

            // Items with all required fields
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items'))->additional([
                'branch_id' => $this->branch_id,
            ]),

            // Timestamps
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];

        $data['supplier'] = null;
        $data['timelines'] = $this->whenLoaded('timelines', fn () => UnifiedTimelineResource::collection($this->timelines));

        return $data;
    }

    /**
     * Get status-specific details based on order status
     * According to requirements 3.1.2.4.3.4.1.1:
     * - Full Approved with date and time arrival
     * - Partial Approval with date and time arrival
     * - Rejected (with branch manager name, image, reason, date and time)
     */
    private function getStatusDetails(): array
    {
        $status = $this->status?->value;

        return match ($status) {
            'rejected' => [
                'branch_manager_name' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy->name) ?? null,
                'branch_manager_image' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy->image_url) ?? null,
                'rejection_reason' => $this->rejection_reason,
                'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),
                'rejected_by' => $this->whenLoaded('requestedBy', function () {
                    return $this->requestedBy ? new BranchManagerResource($this->requestedBy) : null;
                }),
            ],
            'partial_confirmation', 'partial_approved' => [
                'confirmed_items_count' => $this->relationLoaded('items')
                    ? $this->items->whereNotNull('quantity_confirmed')->count()
                    : 0,
                'total_items_count' => $this->total_items,
                'partial_confirmed_at' => $this->confirmed_at?->format('Y-m-d H:i:s'),
                'arrival_date_time' => $this->expected_delivery_at?->format('Y-m-d H:i:s')
                    ?? $this->confirmed_at?->format('Y-m-d H:i:s'),
            ],
            'confirmed', 'fully_approved' => [
                'confirmed_at' => $this->confirmed_at?->format('Y-m-d H:i:s'),
                'arrival_date_time' => $this->expected_delivery_at?->format('Y-m-d H:i:s')
                    ?? $this->confirmed_at?->format('Y-m-d H:i:s'),
                'all_items_confirmed' => $this->relationLoaded('items')
                    ? $this->items->every(fn ($item) => $item->quantity_confirmed !== null)
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
