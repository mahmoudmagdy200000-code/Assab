<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveGoodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Delivery details
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_contact' => ['nullable', 'string', 'max:50'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'arrival_time' => ['nullable', 'date'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_notes' => ['nullable', 'string', 'max:1000'],

            // Items inspection
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0'],
            'items.*.quality' => ['required', 'string', 'in:excellent,normal,poor'],
            'items.*.temperature' => ['nullable', 'numeric'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.photo' => ['nullable', 'file', 'image', 'max:5120'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],

            // Unlisted items (gifts)
            'unlisted_items' => ['nullable', 'array'],
            'unlisted_items.*.item_name' => ['required_with:unlisted_items', 'string', 'max:255'],
            'unlisted_items.*.unit' => ['required_with:unlisted_items', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'unlisted_items.*.quantity' => ['required_with:unlisted_items', 'numeric', 'min:0.001'],
            'unlisted_items.*.quality' => ['required_with:unlisted_items', 'string', 'in:excellent,normal,poor'],
            'unlisted_items.*.price_per_unit' => ['nullable', 'numeric', 'min:0'],
            'unlisted_items.*.reason' => ['nullable', 'string', 'max:500'],

            // Document type
            'document_type' => ['required', 'string', 'in:invoice,delivery_note,receipt_without_document'],
        ];
    }
}
