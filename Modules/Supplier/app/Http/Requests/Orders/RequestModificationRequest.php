<?php

namespace Modules\Supplier\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class RequestModificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $route = $this->route();
        $routeName = $route?->getName();
        $routeAction = $route?->getActionMethod();
        $modificationType = $this->input('modification_type');

        // Rules for time change request
        // Check route name, action method, or modification_type
        if (
            $routeName === 'supplier.pending-orders.request-time-change' ||
            $routeAction === 'requestTimeChange' ||
            $modificationType === 'delivery_time'
        ) {
            return [
                'new_delivery_time' => 'required|date|after:now',
                'reason' => 'required|string|max:500',
                'note' => 'nullable|string|max:1000',
                'modification_type' => 'nullable|in:delivery_time',
                'modification_request' => 'nullable|string|max:2000',
            ];
        }

        // Rules for alternative product request
        // Check route name, action method, or modification_type
        if (
            $routeName === 'supplier.pending-orders.request-alternative' ||
            $routeAction === 'requestAlternative' ||
            $modificationType === 'alternative_product'
        ) {
            return [
                'alternative_item_id' => 'required|uuid',
                'reason' => 'required|string|max:1000',
                'note' => 'nullable|string|max:1000',
                'modification_type' => 'nullable|in:alternative_product',
                'modification_request' => 'nullable|string|max:2000',
            ];
        }

        // Default rules (for general modification request)
        return [
            'modification_type' => 'required|in:quantity,delivery_time,alternative_product',
            'modification_request' => 'required|string|max:2000',
            'suggested_changes' => 'nullable|array',
        ];
    }
}
