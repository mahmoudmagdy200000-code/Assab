<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // These are nullable columns (and a dashboard-provisioned supplier
            // fills only name/email/phone), but the mobile app casts them to a
            // non-null String and crashes on null — coalesce to '' so an
            // address-less / contact-less supplier renders blank, not fatally.
            'email' => $this->email ?? '',
            'phone' => $this->phone ?? '',
            'image' => $this->image_url,
            'address' => $this->address ?? '',

            // Status — coalesced to match SupplierInfoResource / DirectSupplierOrderResource.
            'status' => $this->status ?? 'offline', // string in the Supplier model, not enum
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'is_available' => $this->is_available,
            'is_active' => $this->is_active,

            // Contact methods
            'contact_methods' => $this->contact_methods ?? [],

            // Delivery info
            'default_delivery_hours' => $this->default_delivery_hours,
            'min_order_amount' => $this->min_order_amount ? (float) $this->min_order_amount : null,

            // Statistics
            'average_response_time_hours' => $this->average_response_time_hours ? (float) $this->average_response_time_hours : null,
            'response_rate_percentage' => $this->response_rate_percentage ? (float) $this->response_rate_percentage : null,
            'rating' => $this->rating ? (float) $this->rating : null,
            'total_orders' => $this->total_orders,
            'completed_orders' => $this->completed_orders,

            'last_seen_at' => $this->last_seen_at?->diffForHumans(),
        ];
    }
}
