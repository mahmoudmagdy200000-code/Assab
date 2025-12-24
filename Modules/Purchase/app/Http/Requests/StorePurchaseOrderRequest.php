<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'order_type' => ['required', 'string', 'in:direct_supplier,via_purchasing_officer,internal_transfer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];

        // Rules specific to Direct Supplier Order
        if ($this->input('order_type') === 'direct_supplier') {
            $rules['supplier_id'] = ['required', 'uuid', 'exists:suppliers,id'];
            $rules['quality_level'] = ['required', 'string', 'in:economy,standard,premium'];
            $rules['notification_channels'] = ['required', 'array', 'min:1'];
            $rules['notification_channels.*'] = ['string', 'in:email,whatsapp,app,sms'];
            $rules['items.*.unit_price'] = ['required', 'numeric', 'min:0'];
        }

        // Rules specific to Via Purchasing Officer
        if ($this->input('order_type') === 'via_purchasing_officer') {
            $rules['quality_level'] = ['required', 'string', 'in:economy,standard,premium'];
            $rules['processing_time'] = ['required', 'string', 'in:standard,urgent'];
            $rules['preferred_delivery_date'] = ['required', 'date', 'after:today'];
            $rules['latest_delivery_date'] = ['required', 'date', 'after_or_equal:preferred_delivery_date'];
            $rules['special_instructions'] = ['nullable', 'string', 'max:2000'];
            $rules['items.*.unit_price'] = ['required', 'numeric', 'min:0'];
        }

        // Rules specific to Internal Transfer
        if ($this->input('order_type') === 'internal_transfer') {
            $rules['from_branch_id'] = ['required', 'uuid', 'exists:branches,id'];
            $rules['priority'] = ['nullable', 'string', 'in:high,normal'];
            $rules['items.*.available_in_source'] = ['nullable', 'numeric', 'min:0'];
            $rules['items.*.expiry_date'] = ['nullable', 'date'];
            $rules['items.*.cooling_status'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'order_type.required' => 'Order type is required.',
            'order_type.in' => 'Invalid order type. Must be one of: direct_supplier, via_purchasing_officer, internal_transfer.',

            // Direct Supplier messages
            'supplier_id.required' => 'Please select a supplier.',
            'supplier_id.exists' => 'The selected supplier does not exist.',
            'quality_level.required' => 'Please select a quality level.',
            'notification_channels.required' => 'Please select at least one notification method.',

            // Via Purchasing Officer messages
            'processing_time.required' => 'Please select a processing time.',
            'preferred_delivery_date.required' => 'Preferred delivery date is required.',
            'preferred_delivery_date.after' => 'Preferred delivery date must be in the future.',
            'latest_delivery_date.required' => 'Latest delivery date is required.',
            'latest_delivery_date.after_or_equal' => 'Latest delivery date must be on or after the preferred date.',

            // Internal Transfer messages
            'from_branch_id.required' => 'Please select a source branch.',
            'from_branch_id.exists' => 'The selected branch does not exist.',

            // Items messages
            'items.required' => 'Please add at least one item to the order.',
            'items.*.item_id.required' => 'Item ID is required for each item.',
            'items.*.item_id.exists' => 'One or more selected items do not exist.',
            'items.*.quantity.required' => 'Quantity is required for each item.',
            'items.*.quantity.min' => 'Quantity must be greater than 0.',
            'items.*.unit_price.required' => 'Unit price is required for each item.',
        ];
    }
}
