<?php

namespace Modules\Supplier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->image_url,
            'address' => $this->address,
            'tax_id' => $this->tax_id,
            'company_name' => $this->company_name,

            // Account Status
            'is_active' => $this->is_active,
            'is_first_login' => $this->is_first_login,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'is_available' => $this->is_available,

            // Settings
            'language' => $this->language,
            'theme' => $this->theme,
            'notification_preferences' => $this->notification_preferences ?? [],

            // Business Information
            'service_areas' => $this->service_areas ?? [],
            'working_hours' => $this->working_hours ?? [],
            'holiday_schedules' => $this->holiday_schedules ?? [],

            // Contact Methods
            'contact_methods' => $this->contact_methods ?? [],

            // Delivery Information
            'default_delivery_hours' => $this->default_delivery_hours,
            'min_order_amount' => $this->min_order_amount ? (float) $this->min_order_amount : null,

            // Performance Metrics
            'average_response_time_hours' => $this->average_response_time_hours ? (float) $this->average_response_time_hours : null,
            'response_rate_percentage' => $this->response_rate_percentage ? (float) $this->response_rate_percentage : null,
            'rating' => $this->rating ? (float) $this->rating : null,
            'total_orders' => $this->total_orders,
            'completed_orders' => $this->completed_orders,

            // Categories
            'categories' => $this->categories ?? [],

            // Timestamps
            'created_by_admin_at' => $this->created_by_admin_at?->toDateTimeString(),
            'last_seen_at' => $this->last_seen_at?->diffForHumans(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
