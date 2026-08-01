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

            // Delivery info — every numeric is coalesced for the same reason
            // the strings above are: the app casts them with `as num`, and a
            // dashboard-provisioned supplier leaves all of them null, which
            // crashed the order screens with «type 'Null' is not a subtype of
            // type 'num' in type cast».
            'default_delivery_hours' => (int) ($this->default_delivery_hours ?? 0),
            'min_order_amount' => (float) ($this->min_order_amount ?? 0),

            // Statistics
            'average_response_time_hours' => (float) ($this->average_response_time_hours ?? 0),
            'response_rate_percentage' => (float) ($this->response_rate_percentage ?? 0),
            'rating' => (float) ($this->rating ?? 0),
            'total_orders' => (int) ($this->total_orders ?? 0),
            'completed_orders' => (int) ($this->completed_orders ?? 0),

            'last_seen_at' => $this->last_seen_at?->diffForHumans(),
        ];
    }
}
