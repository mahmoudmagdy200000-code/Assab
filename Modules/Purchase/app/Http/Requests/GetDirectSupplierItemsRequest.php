<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetDirectSupplierItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'uuid', 'exists:purchase_suppliers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', 'exists:branch_item,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
        ];
    }
}
