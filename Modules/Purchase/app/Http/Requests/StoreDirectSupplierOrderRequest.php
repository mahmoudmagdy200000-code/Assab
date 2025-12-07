<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Purchase\Enums\NotificationChannel;
use Modules\Purchase\Enums\QualityLevel;

class StoreDirectSupplierOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'uuid', 'exists:purchase_suppliers,id'],
            'quality_level' => ['required', 'string', 'in:economy,standard,premium'],
            'notification_channels' => ['required', 'array', 'min:1'],
            'notification_channels.*' => ['string', 'in:email,whatsapp,app,sms'],
            'message' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'uuid'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.item_logo' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['required', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
            'items.*.category' => ['nullable', 'string', 'max:100'],
            'items.*.subcategory' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Please select a supplier.',
            'supplier_id.exists' => 'The selected supplier does not exist.',
            'quality_level.required' => 'Please select a quality level.',
            'notification_channels.required' => 'Please select at least one notification method.',
            'items.required' => 'Please add at least one item to the order.',
            'items.*.item_name.required' => 'Item name is required.',
            'items.*.quantity.required' => 'Quantity is required for each item.',
            'items.*.quantity.min' => 'Quantity must be greater than 0.',
            'items.*.unit_price.required' => 'Unit price is required for each item.',
        ];
    }
}

