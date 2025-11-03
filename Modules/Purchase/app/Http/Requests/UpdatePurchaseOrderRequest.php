<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'priority' => 'nullable|in:high,normal,low',
            'delivery_date' => 'nullable|date',
            'latest_delivery_date' => 'nullable|date|after_or_equal:delivery_date',
            'special_instructions' => 'nullable|string|max:2000',
            'message' => 'nullable|string|max:1000',
            'items' => 'nullable|array',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|in:KG,PK,L',
            'items.*.quality' => 'nullable|in:economy,standard,premium',
            'items.*.rate' => 'required|numeric|min:0',
        ];
    }
}
