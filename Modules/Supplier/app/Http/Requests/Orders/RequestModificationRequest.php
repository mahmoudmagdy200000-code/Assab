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
        // Rules for time change request
        if ($this->route()->getName() === 'supplier.pending-orders.request-time-change') {
            return [
                'new_delivery_time' => 'required|date|after:now',
                'reason' => 'required|string|max:500',
                'note' => 'nullable|string|max:1000',
            ];
        }

        // Rules for alternative product request
        if ($this->route()->getName() === 'supplier.pending-orders.request-alternative') {
            return [
                'alternative_item_id' => 'required|uuid',
                'alternative_item_name' => 'required|string|max:255',
                'price' => 'nullable|numeric|min:0',
                'reason' => 'required|string|max:500',
                'note' => 'nullable|string|max:1000',
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

