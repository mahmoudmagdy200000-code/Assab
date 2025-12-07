<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveWithoutOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Supplier and delivery
            'supplier_id' => ['nullable', 'uuid', 'exists:purchase_suppliers,id'],
            'supplier_name' => ['required_without:supplier_id', 'string', 'max:255'],
            'delivery_reference' => ['nullable', 'string', 'max:100'],

            // Delivery details
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_contact' => ['nullable', 'string', 'max:50'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'arrival_time' => ['nullable', 'date'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_notes' => ['nullable', 'string', 'max:1000'],

            // Items
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.item_id' => ['nullable', 'uuid'],
            'items.*.unit' => ['required', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.price_per_unit' => ['required', 'numeric', 'min:0'],
            'items.*.quality' => ['required', 'string', 'in:excellent,normal,poor'],
            'items.*.temperature' => ['nullable', 'numeric'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.payment_terms' => ['nullable', 'string', 'max:100'],
            'items.*.note' => ['nullable', 'string', 'max:500'],

            // Invoice
            'invoice_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.exists' => 'Selected supplier does not exist.',
            'supplier_name.required_without' => 'Please enter supplier name or select an existing supplier.',
            'items.required' => 'Please add at least one item.',
            'items.*.product_name.required' => 'Product name is required.',
            'items.*.quantity.required' => 'Quantity is required.',
            'items.*.price_per_unit.required' => 'Price per unit is required.',
        ];
    }
}

