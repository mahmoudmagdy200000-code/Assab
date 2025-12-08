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
            'items.*.item_id' => ['required', 'uuid', 'exists:branch_item,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
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
            'items.*.item_id.required' => 'Item ID is required for each item.',
            'items.*.item_id.exists' => 'One or more selected items do not exist.',
            'items.*.quantity.required' => 'Quantity is required for each item.',
            'items.*.quantity.min' => 'Quantity must be greater than 0.',
            'items.*.unit_price.required' => 'Unit price is required for each item.',
        ];
    }
}

